<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\InhealthGuarantee;
use App\Modules\Integration\Models\PayerReference;
use App\Modules\Integration\Services\Inhealth\InhealthService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mandiri Inhealth (domain L item Q).
 *
 * Yang paling perlu dikunci:
 *
 * 1. ELIGIBILITAS GAGAL BUKAN "TIDAK ELIGIBLE". Menyamakannya membuat
 *    pasien yang sebenarnya berhak diminta membayar sendiri.
 * 2. SATU KUNJUNGAN, SATU SJP BERLAKU — dua surat jaminan ditagihkan dua
 *    kali, dan salah satunya pasti ditolak.
 * 3. TAGIHAN HANYA ATAS SJP YANG TERBIT, dan rinciannya dibekukan saat
 *    diajukan.
 */
class InhealthTest extends TestCase
{
    use RefreshDatabase;

    private InhealthService $inhealth;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->inhealth = app(InhealthService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-inhealth', 'name' => 'Petugas Inhealth Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ----------------------------------------------------------- eligibilitas

    #[Test]
    public function peserta_aktif_tercatat_berikut_nama_dan_plannya(): void
    {
        $hasil = $this->inhealth->checkEligibility('0011234567', null, $this->petugas->id);

        $this->assertTrue($hasil->is_eligible);
        $this->assertStringContainsString('Peserta Inhealth', $hasil->member_name);
        $this->assertSame('Inhealth Managed Care Gold', $hasil->plan_name);
        $this->assertNull($hasil->error_message);
    }

    #[Test]
    public function peserta_tidak_aktif_tercatat_sebagai_tidak_eligible(): void
    {
        $hasil = $this->inhealth->checkEligibility('9011234567');

        $this->assertFalse($hasil->is_eligible);
        $this->assertNull($hasil->error_message);
    }

    /**
     * ATURAN PERTAMA: gagal itu null, bukan false.
     */
    #[Test]
    public function panggilan_gagal_menghasilkan_null_bukan_tidak_eligible(): void
    {
        $hasil = $this->inhealth->checkEligibility('8011234567');

        $this->assertNull($hasil->is_eligible);
        $this->assertStringContainsString('gangguan', $hasil->error_message);
    }

    #[Test]
    public function nomor_peserta_kosong_ditolak_sebelum_memanggil(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nomor peserta Inhealth wajib diisi');

        $this->inhealth->checkEligibility('   ');
    }

    // -------------------------------------------------------------------- SJP

    #[Test]
    public function sjp_terbit_dengan_nomor_dari_inhealth(): void
    {
        $registrasi = $this->daftarkan();

        $sjp = $this->inhealth->issueGuarantee($registrasi->id, '0011234567', [
            'poli_code' => 'INT',
            'diagnosis_code' => 'E11.9',
        ], $this->petugas->id);

        $this->assertSame(InhealthGuarantee::TERBIT, $sjp->status);
        $this->assertStringStartsWith('SJP', $sjp->sjp_number);
        $this->assertNotNull($sjp->issued_at);
    }

    /**
     * ATURAN KEDUA: satu kunjungan, satu SJP berlaku.
     */
    #[Test]
    public function satu_kunjungan_tidak_boleh_punya_dua_sjp_berlaku(): void
    {
        $registrasi = $this->daftarkan();

        $this->inhealth->issueGuarantee($registrasi->id, '0011234567', ['poli_code' => 'INT']);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('ditagihkan dua kali');

        $this->inhealth->issueGuarantee($registrasi->id, '0011234567', ['poli_code' => 'INT']);
    }

    #[Test]
    public function sjp_yang_dibatalkan_membuka_jalan_untuk_sjp_baru(): void
    {
        $registrasi = $this->daftarkan();

        $pertama = $this->inhealth->issueGuarantee($registrasi->id, '0011234567', ['poli_code' => 'INT']);
        $this->inhealth->cancelGuarantee($pertama, 'Salah input nomor peserta.');

        $kedua = $this->inhealth->issueGuarantee($registrasi->id, '0011234568', ['poli_code' => 'INT']);

        $this->assertSame(InhealthGuarantee::TERBIT, $kedua->status);
        $this->assertNotSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function sjp_tanpa_kode_poli_ditolak_sebelum_memanggil(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Kode poli tujuan versi Inhealth wajib');

        $this->inhealth->issueGuarantee($registrasi->id, '0011234567');
    }

    #[Test]
    public function kegagalan_penerbitan_sjp_tetap_tercatat(): void
    {
        $registrasi = $this->daftarkan();

        $sjp = $this->inhealth->issueGuarantee($registrasi->id, '8011234567', ['poli_code' => 'INT']);

        $this->assertSame(InhealthGuarantee::GAGAL, $sjp->status);
        $this->assertNull($sjp->sjp_number);
        $this->assertStringContainsString('gangguan', $sjp->error_message);
    }

    /** Pembatalan yang ditolak Inhealth tidak mengubah status lokal. */
    #[Test]
    public function pembatalan_yang_ditolak_tidak_mengubah_status_lokal(): void
    {
        $sjp = $this->sjpTerbit();
        $sjp->update(['sjp_number' => null]);

        $hasil = $this->inhealth->cancelGuarantee($sjp->refresh(), 'Salah input.');

        $this->assertSame(InhealthGuarantee::TERBIT, $hasil->status);
        $this->assertNull($hasil->cancelled_at);
        $this->assertStringContainsString('wajib diisi', $hasil->error_message);
    }

    // ---------------------------------------------------------------- tagihan

    #[Test]
    public function tagihan_diajukan_atas_sjp_yang_terbit(): void
    {
        $sjp = $this->sjpTerbit();

        $hasil = $this->inhealth->submitBilling($sjp, [
            ['kode' => 'TND-01', 'nama' => 'Konsultasi', 'subtotal' => 150000],
            ['kode' => 'OBT-01', 'nama' => 'Metformin', 'subtotal' => 30000],
        ]);

        $this->assertSame(InhealthGuarantee::TAGIHAN_DIAJUKAN, $hasil->billing_status);
        $this->assertSame('180000.00', $hasil->billed_amount);
        $this->assertCount(2, $hasil->billing_items);
        $this->assertNotNull($hasil->billed_at);
    }

    /**
     * ATURAN KETIGA: menagih atas surat jaminan yang tidak pernah terbit
     * adalah menagih tanpa dasar.
     */
    #[Test]
    public function tagihan_atas_sjp_yang_gagal_ditolak(): void
    {
        $registrasi = $this->daftarkan();
        $gagal = $this->inhealth->issueGuarantee($registrasi->id, '8011234567', ['poli_code' => 'INT']);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('menagih tanpa dasar');

        $this->inhealth->submitBilling($gagal, [['subtotal' => 100000]]);
    }

    /** Dijaga basis data juga, bukan cuma service. */
    #[Test]
    public function basis_data_menolak_tagihan_tanpa_sjp_terbit(): void
    {
        $registrasi = $this->daftarkan();
        $gagal = $this->inhealth->issueGuarantee($registrasi->id, '8011234567', ['poli_code' => 'INT']);

        $this->expectException(QueryException::class);

        $gagal->update(['billing_status' => InhealthGuarantee::TAGIHAN_DIAJUKAN]);
    }

    #[Test]
    public function tagihan_tanpa_rincian_ditolak(): void
    {
        $sjp = $this->sjpTerbit();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Rincian tagihan wajib diisi');

        $this->inhealth->submitBilling($sjp, []);
    }

    #[Test]
    public function sjp_yang_sudah_ditagihkan_tidak_bisa_dibatalkan(): void
    {
        $sjp = $this->sjpTerbit();
        $this->inhealth->submitBilling($sjp, [['subtotal' => 100000]]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tarik dulu tagihannya');

        $this->inhealth->cancelGuarantee($sjp->refresh(), 'Salah input.');
    }

    /** Pendapatan yang sudah dilayani tapi belum ditagihkan harus terlihat. */
    #[Test]
    public function sjp_yang_belum_ditagihkan_muncul_di_daftar_tertunda(): void
    {
        $sjp = $this->sjpTerbit();

        $this->assertCount(1, $this->inhealth->unbilled());

        $this->inhealth->submitBilling($sjp, [['subtotal' => 100000]]);

        $this->assertCount(0, $this->inhealth->unbilled());
    }

    // -------------------------------------------------------------- referensi

    #[Test]
    public function referensi_inhealth_tersimpan_lewat_mekanisme_yang_sama(): void
    {
        $jumlah = $this->inhealth->refreshReferences('poli');

        $this->assertSame(2, $jumlah);
        $this->assertSame(2, PayerReference::query()
            ->where('payer', InhealthService::SISTEM)
            ->where('reference_type', 'poli')
            ->count());
    }

    #[Test]
    public function jenis_referensi_inhealth_yang_asing_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->inhealth->refreshReferences('golongan-darah');
    }

    // ------------------------------------------------------------------ bantu

    private function sjpTerbit(): InhealthGuarantee
    {
        return $this->inhealth->issueGuarantee(
            $this->daftarkan()->id,
            '0011234567',
            ['poli_code' => 'INT'],
            $this->petugas->id
        );
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Inhealth ' . $urut, 'sex' => 'P', 'birth_date' => '1988-08-08',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
