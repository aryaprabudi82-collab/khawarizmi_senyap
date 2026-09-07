<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\BpjsAdmissionOrder;
use App\Modules\Integration\Models\BpjsSep;
use App\Modules\Integration\Models\Claim;
use App\Modules\Integration\Models\SepReclassification;
use App\Modules\Integration\Services\Bpjs\AdmissionOrderService;
use App\Modules\Integration\Services\Bpjs\SepService;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Surat Perintah Rawat Inap & reklasifikasi SEP (domain L item L).
 *
 * Yang paling perlu dikunci:
 *
 * 1. KLASIFIKASI LAMA DISIMPAN, BUKAN DITIMPA. Reklasifikasi mengubah apa
 *    yang ditagihkan ke negara, dan perubahan tagihan tanpa jejak tidak
 *    bisa dibedakan dari kecurangan.
 * 2. SEP YANG SUDAH DIKLAIM TIDAK BISA DIREKLASIFIKASI. Isinya sudah
 *    dibekukan; yang berubah harus lewat revisi klaim.
 * 3. PEMBATALAN YANG DITOLAK BPJS TIDAK MENGUBAH STATUS LOKAL — kalau
 *    diubah sepihak, kita mengira surat batal sementara BPJS masih
 *    menganggapnya berlaku.
 */
class BpjsAdmissionOrderTest extends TestCase
{
    use RefreshDatabase;

    private AdmissionOrderService $spri;
    private SepService $sep;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->spri = app(AdmissionOrderService::class);
        $this->sep = app(SepService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-spri', 'name' => 'Petugas Ranap Uji',
            'password' => 'password', 'is_active' => true,
        ]);

        app(IdentityMappingService::class)->setManually(
            'bpjs', 'poli', 'organization',
            Unit::query()->where('code', 'POL-UMUM')->value('id'),
            'RJ0001', $this->petugas->id
        );
    }

    // ------------------------------------------------------------ Surat PRI

    #[Test]
    public function surat_pri_terbit_dengan_nomor_dari_bpjs(): void
    {
        $surat = $this->spri->issue($this->surat(), $this->petugas->id);

        $this->assertSame(BpjsAdmissionOrder::TERBIT, $surat->status);
        $this->assertNotNull($surat->order_number);
        $this->assertStringStartsWith('PRI', $surat->order_number);
    }

    /** Dua surat atas kunjungan yang sama menerbitkan dua SEP rawat inap. */
    #[Test]
    public function satu_kunjungan_hanya_boleh_punya_satu_surat_berlaku(): void
    {
        $registrasi = $this->daftarkan();

        $this->spri->issue($this->surat(['registration_id' => $registrasi->id]));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah punya surat perintah rawat inap');

        $this->spri->issue($this->surat(['registration_id' => $registrasi->id]));
    }

    /** Surat yang dibatalkan membuka jalan untuk surat baru. */
    #[Test]
    public function surat_yang_dibatalkan_membuka_jalan_untuk_surat_baru(): void
    {
        $registrasi = $this->daftarkan();

        $pertama = $this->spri->issue($this->surat(['registration_id' => $registrasi->id]));
        $this->spri->cancel($pertama, 'Pasien menunda rawat inap.');

        $kedua = $this->spri->issue($this->surat(['registration_id' => $registrasi->id]));

        $this->assertSame(BpjsAdmissionOrder::TERBIT, $kedua->status);
        $this->assertNotSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function tanggal_rencana_masuk_yang_sudah_lewat_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sebelum hari ini');

        $this->spri->issue($this->surat(['planned_date' => now()->subDay()->toDateString()]));
    }

    #[Test]
    public function kegagalan_penerbitan_tetap_tercatat(): void
    {
        $surat = $this->spri->issue($this->surat(['card_number' => '8001234567890']));

        $this->assertSame(BpjsAdmissionOrder::GAGAL, $surat->status);
        $this->assertNull($surat->order_number);
        $this->assertStringContainsString('gangguan', $surat->response_message);
    }

    #[Test]
    public function surat_yang_sudah_dipakai_tidak_bisa_dibatalkan(): void
    {
        $surat = $this->spri->issue($this->surat());
        $this->spri->markUsed($surat, 'SEP-RANAP-0001');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('batalkan SEP-nya lebih dulu');

        $this->spri->cancel($surat->refresh(), 'Salah input.');
    }

    /**
     * ATURAN KETIGA: pembatalan yang ditolak BPJS tidak mengubah status
     * lokal.
     */
    #[Test]
    public function pembatalan_yang_ditolak_bpjs_tidak_mengubah_status_lokal(): void
    {
        $surat = $this->spri->issue($this->surat());
        // Nomor surat dikosongkan supaya adapter menolak pembatalannya.
        $surat->update(['order_number' => null]);

        $hasil = $this->spri->cancel($surat->refresh(), 'Pasien membatalkan.');

        $this->assertSame(BpjsAdmissionOrder::TERBIT, $hasil->status);
        $this->assertNull($hasil->cancelled_at);
        $this->assertStringContainsString('wajib diisi', $hasil->response_message);
    }

    /** Surat menggantung menahan penerbitan surat baru — harus terlihat. */
    #[Test]
    public function surat_yang_lewat_tanggal_rencananya_muncul_di_daftar_menggantung(): void
    {
        $surat = $this->spri->issue($this->surat());
        $surat->update(['planned_date' => now()->subWeek()->toDateString()]);

        $menggantung = $this->spri->stale();

        $this->assertCount(1, $menggantung);
        $this->assertTrue($menggantung->first()->isStale());
    }

    // ------------------------------------------------------- reklasifikasi

    /**
     * ATURAN PERTAMA: yang lama disimpan, bukan ditimpa.
     */
    #[Test]
    public function reklasifikasi_menyimpan_klasifikasi_lama_berikut_alasannya(): void
    {
        $sep = $this->sepTerbit();

        $riwayat = $this->spri->reclassify($sep, [
            'new_service_type' => BpjsSep::JENIS_RANAP,
            'previous_class' => '3',
            'new_class' => '2',
            'reason' => 'Pasien diputuskan dirawat inap setelah observasi IGD.',
        ], $this->petugas->id);

        $this->assertSame(SepReclassification::DITERIMA, $riwayat->status);
        $this->assertSame(BpjsSep::JENIS_RALAN, $riwayat->previous_service_type);
        $this->assertSame(BpjsSep::JENIS_RANAP, $riwayat->new_service_type);
        $this->assertSame('3', $riwayat->previous_class);
        $this->assertStringContainsString('observasi IGD', $riwayat->reason);

        // SEP-nya sendiri ikut berubah, tapi jejaknya tetap ada.
        $this->assertSame(BpjsSep::JENIS_RANAP, $sep->refresh()->jenis_pelayanan);
        $this->assertCount(1, $this->spri->reclassificationHistory($sep));
    }

    #[Test]
    public function reklasifikasi_tanpa_alasan_ditolak(): void
    {
        $sep = $this->sepTerbit();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Alasan reklasifikasi wajib diisi');

        $this->spri->reclassify($sep, ['new_service_type' => BpjsSep::JENIS_RANAP, 'reason' => '  ']);
    }

    #[Test]
    public function reklasifikasi_ke_klasifikasi_yang_sama_ditolak(): void
    {
        $sep = $this->sepTerbit();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Tidak ada yang berubah');

        $this->spri->reclassify($sep, [
            'new_service_type' => BpjsSep::JENIS_RALAN,
            'reason' => 'Tidak ada perubahan.',
        ]);
    }

    /**
     * ATURAN KEDUA: setelah klaim dikirim, isinya sudah dibekukan.
     */
    #[Test]
    public function sep_yang_sudah_diklaim_tidak_bisa_direklasifikasi(): void
    {
        $sep = $this->sepTerbit();

        Claim::query()->create([
            'claim_number' => 'KLM-0001',
            'claim_type' => Claim::INACBG,
            'registration_id' => $sep->registration_id,
            'registration_number' => $sep->registration_number,
            'patient_id' => 1,
            'patient_name' => 'Pasien SPRI Uji',
            'card_number' => $sep->no_kartu,
            'sep_number' => $sep->sep_number,
            'care_type' => 'ralan',
            'admitted_on' => now()->toDateString(),
            'hospital_charge' => 500000,
            'status' => Claim::TERKIRIM,
            'submitted_at' => now(),
        ]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('revisi klaim');

        $this->spri->reclassify($sep, [
            'new_service_type' => BpjsSep::JENIS_RANAP,
            'reason' => 'Terlambat direklasifikasi.',
        ]);
    }

    /** Klaim yang masih draf belum membekukan apa pun. */
    #[Test]
    public function klaim_yang_masih_draf_tidak_menghalangi_reklasifikasi(): void
    {
        $sep = $this->sepTerbit();

        Claim::query()->create([
            'claim_number' => 'KLM-0002',
            'claim_type' => Claim::INACBG,
            'registration_id' => $sep->registration_id,
            'registration_number' => $sep->registration_number,
            'patient_id' => 1,
            'patient_name' => 'Pasien SPRI Uji',
            'card_number' => $sep->no_kartu,
            'sep_number' => $sep->sep_number,
            'care_type' => 'ralan',
            'admitted_on' => now()->toDateString(),
            'hospital_charge' => 500000,
            'status' => Claim::DRAF,
        ]);

        $riwayat = $this->spri->reclassify($sep, [
            'new_service_type' => BpjsSep::JENIS_RANAP,
            'reason' => 'Diputuskan rawat inap sebelum klaim dikirim.',
        ]);

        $this->assertSame(SepReclassification::DITERIMA, $riwayat->status);
    }

    #[Test]
    public function sep_yang_dibatalkan_tidak_bisa_direklasifikasi(): void
    {
        $sep = $this->sepTerbit();
        $this->sep->cancel($sep, 'Salah input.');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Hanya SEP yang terbit');

        $this->spri->reclassify($sep->refresh(), [
            'new_service_type' => BpjsSep::JENIS_RANAP,
            'reason' => 'Terlambat.',
        ]);
    }

    // ------------------------------------------------------------------ bantu

    private function surat(array $ubah = []): array
    {
        return array_merge([
            'patient_id' => 1,
            'patient_mrn' => 'RM-000001',
            'patient_name' => 'Pasien SPRI Uji',
            'card_number' => '0001234567890',
            'planned_date' => now()->addDay()->toDateString(),
            'practitioner_name' => 'dr. Ranap Uji',
            'poli_code' => 'RJ0001',
            'reason' => 'Perlu observasi lanjutan.',
        ], $ubah);
    }

    private function sepTerbit(): BpjsSep
    {
        $registrasi = $this->daftarkan('BPJS');

        return $this->sep->create($registrasi->id, '0001234567890', 'RJ0001', $this->petugas->id);
    }

    private function daftarkan(string $kodePenjamin = 'BPJS'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien SPRI ' . $urut, 'sex' => 'L', 'birth_date' => '1975-02-02',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $kodePenjamin)->value('id'),
        );
    }
}
