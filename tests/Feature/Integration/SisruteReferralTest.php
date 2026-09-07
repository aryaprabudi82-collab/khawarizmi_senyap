<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\OutgoingReferral;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\PayerReference;
use App\Modules\Integration\Models\SisruteReferral;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\Sisrute\SisruteReferralService;
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
 * Rujukan Sisrute (domain L item P).
 *
 * Yang paling perlu dikunci:
 *
 * 1. PENOLAKAN WAJIB BERALASAN. Rumah sakit perujuk yang ditolak tanpa
 *    alasan harus mencari secara buta sementara pasiennya menunggu.
 * 2. MENERIMA RUJUKAN BUKAN MENDAFTARKAN PASIEN. Menerima berarti tempat
 *    disanggupi; kunjungan baru ada saat pasiennya tiba.
 * 3. IDENTITAS DARI PERUJUK TIDAK MEMBUAT PASIEN DI MASTER KITA — data
 *    yang belum diverifikasi melahirkan pasien ganda, dan pasien ganda
 *    berarti riwayat yang terbelah dua.
 */
class SisruteReferralTest extends TestCase
{
    use RefreshDatabase;

    private SisruteReferralService $sisrute;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->sisrute = app(SisruteReferralService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-sisrute', 'name' => 'Petugas Rujukan Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ arah keluar

    #[Test]
    public function rujukan_keluar_diajukan_tanpa_menyalin_isinya(): void
    {
        $rujukan = $this->rujukanKeluar();

        $pengajuan = $this->sisrute->sendOutgoing($rujukan->id, [
            'reason_code' => 'SPESIALIS',
            'clinical_summary' => 'Nyeri dada khas iskemik, perlu kateterisasi.',
        ], $this->petugas->id);

        $this->assertSame(SisruteReferral::DIAJUKAN, $pengajuan->status);
        $this->assertNotNull($pengajuan->sisrute_number);
        $this->assertSame($rujukan->id, $pengajuan->outgoing_referral_id);

        // Isi rujukannya tetap milik konteks encounter — yang ada di sini
        // cuma pengajuannya.
        $this->assertNull($pengajuan->patient_identity_number);
        $this->assertSame(1, OutgoingReferral::query()->count());
    }

    /**
     * Rumah sakit tujuan menilai kesanggupan dari kondisi pasien, bukan
     * dari nama diagnosisnya saja.
     */
    #[Test]
    public function pengajuan_tanpa_ringkasan_kondisi_ditolak(): void
    {
        $rujukan = $this->rujukanKeluar();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Ringkasan kondisi pasien wajib diisi');

        $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => '   ']);
    }

    /** Dua pengajuan membuat dua rumah sakit menyiapkan tempat untuk satu pasien. */
    #[Test]
    public function satu_rujukan_hanya_boleh_punya_satu_pengajuan_berjalan(): void
    {
        $rujukan = $this->rujukanKeluar();

        $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => 'Perlu ICU.']);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('masih berjalan');

        $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => 'Perlu ICU.']);
    }

    /** Rumah sakit tujuan yang penuh adalah penolakan, dan alasannya disebut. */
    #[Test]
    public function tujuan_yang_penuh_dicatat_sebagai_penolakan_beralasan(): void
    {
        $rujukan = $this->rujukanKeluar(kodeTujuan: 'PENUH-01');

        $pengajuan = $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => 'Perlu ICU.']);

        $this->assertSame(SisruteReferral::DITOLAK, $pengajuan->status);
        $this->assertStringContainsString('penuh', $pengajuan->rejection_reason);
        $this->assertNotNull($pengajuan->responded_at);
    }

    #[Test]
    public function kegagalan_sisrute_tercatat_tanpa_menghentikan_pencatatan(): void
    {
        $rujukan = $this->rujukanKeluar(kodeTujuan: 'ZZZ-01');

        $pengajuan = $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => 'Perlu ICU.']);

        $this->assertSame(SisruteReferral::GAGAL, $pengajuan->status);
        $this->assertStringContainsString('gangguan', $pengajuan->response_message);
    }

    /** Penarikan yang ditolak Sisrute tidak mengubah status lokal. */
    #[Test]
    public function penarikan_yang_ditolak_tidak_mengubah_status_lokal(): void
    {
        $pengajuan = $this->sisrute->sendOutgoing(
            $this->rujukanKeluar()->id,
            ['clinical_summary' => 'Perlu ICU.']
        );
        $pengajuan->update(['sisrute_number' => null]);

        $hasil = $this->sisrute->cancelOutgoing($pengajuan->refresh(), 'Pasien memilih rumah sakit lain.');

        $this->assertSame(SisruteReferral::DIAJUKAN, $hasil->status);
        $this->assertStringContainsString('wajib diisi', $hasil->response_message);
    }

    #[Test]
    public function penarikan_berhasil_membuka_jalan_untuk_pengajuan_baru(): void
    {
        $rujukan = $this->rujukanKeluar();

        $pertama = $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => 'Perlu ICU.']);
        $this->sisrute->cancelOutgoing($pertama, 'Keluarga memilih rumah sakit lain.');

        $kedua = $this->sisrute->sendOutgoing($rujukan->id, ['clinical_summary' => 'Perlu ICU.']);

        $this->assertSame(SisruteReferral::DIAJUKAN, $kedua->status);
    }

    // ------------------------------------------------------------- arah masuk

    #[Test]
    public function rujukan_masuk_tercatat_berikut_ringkasan_klinisnya(): void
    {
        $hasil = $this->sisrute->fetchIncoming(
            now()->subDay()->toDateString(),
            now()->toDateString(),
            $this->petugas->id
        );

        $this->assertSame(1, $hasil['baru']);

        $masuk = SisruteReferral::query()->where('direction', SisruteReferral::MASUK)->firstOrFail();

        $this->assertSame('RSUD Perujuk', $masuk->origin_facility_name);
        $this->assertStringContainsString('ST elevasi', $masuk->clinical_summary);
        $this->assertSame(SisruteReferral::DIAJUKAN, $masuk->status);
    }

    /** Mengambil berulang tidak menggandakan permintaan yang sama. */
    #[Test]
    public function pengambilan_berulang_tidak_menggandakan_rujukan_masuk(): void
    {
        $this->sisrute->fetchIncoming(now()->subDay()->toDateString(), now()->toDateString());
        $kedua = $this->sisrute->fetchIncoming(now()->subDay()->toDateString(), now()->toDateString());

        $this->assertSame(0, $kedua['baru']);
        $this->assertSame(1, $kedua['sudah_ada']);
        $this->assertSame(1, SisruteReferral::query()->where('direction', SisruteReferral::MASUK)->count());
    }

    /**
     * ATURAN KETIGA: identitas dari perujuk tidak membuat pasien di master.
     */
    #[Test]
    public function identitas_dari_perujuk_tidak_membuat_pasien_di_master_kita(): void
    {
        $this->sisrute->fetchIncoming(now()->subDay()->toDateString(), now()->toDateString());

        $masuk = SisruteReferral::query()->where('direction', SisruteReferral::MASUK)->firstOrFail();

        $this->assertSame('3175010101800001', $masuk->patient_identity_number);
        // Tidak ada pasien baru yang dibuat dari data yang belum diperiksa.
        $this->assertSame(0, \App\Modules\Identity\Models\Patient::query()->count());
    }

    /**
     * ATURAN PERTAMA: penolakan wajib beralasan.
     */
    #[Test]
    public function penolakan_rujukan_masuk_tanpa_alasan_ditolak(): void
    {
        $masuk = $this->rujukanMasuk();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('wajib disertai alasan');

        $this->sisrute->respond($masuk, accepted: false, reason: '   ');
    }

    #[Test]
    public function penolakan_beralasan_tersimpan_berikut_penjawabnya(): void
    {
        $masuk = $this->rujukanMasuk();

        $hasil = $this->sisrute->respond(
            $masuk,
            accepted: false,
            reason: 'ICU penuh, tidak ada ventilator tersedia.',
            responderName: 'dr. Jaga IGD',
            actorId: $this->petugas->id
        );

        $this->assertSame(SisruteReferral::DITOLAK, $hasil->status);
        $this->assertStringContainsString('ventilator', $hasil->rejection_reason);
        $this->assertSame('dr. Jaga IGD', $hasil->responded_by_name);
    }

    /** Dijaga basis data juga, bukan cuma service. */
    #[Test]
    public function basis_data_menolak_penolakan_tanpa_alasan(): void
    {
        $masuk = $this->rujukanMasuk();

        $this->expectException(QueryException::class);

        $masuk->update(['status' => SisruteReferral::DITOLAK, 'rejection_reason' => null]);
    }

    /**
     * ATURAN KEDUA: menerima bukan mendaftarkan.
     */
    #[Test]
    public function menerima_rujukan_tidak_membuat_kunjungan(): void
    {
        $masuk = $this->rujukanMasuk();

        $diterima = $this->sisrute->respond($masuk, accepted: true, responderName: 'dr. Jaga IGD');

        $this->assertSame(SisruteReferral::DITERIMA, $diterima->status);
        $this->assertNull($diterima->registration_id);
        $this->assertTrue($diterima->isAwaitingArrival());
        $this->assertSame(0, Registration::query()->count());
    }

    #[Test]
    public function pasien_yang_tiba_baru_dihubungkan_ke_kunjungannya(): void
    {
        $masuk = $this->rujukanMasuk();
        $diterima = $this->sisrute->respond($masuk, accepted: true);

        $registrasi = $this->daftarkan();
        $tiba = $this->sisrute->markArrived($diterima, $registrasi->id);

        $this->assertSame(SisruteReferral::TIBA, $tiba->status);
        $this->assertSame($registrasi->id, $tiba->registration_id);
        $this->assertFalse($tiba->isAwaitingArrival());
        $this->assertCount(0, $this->sisrute->awaitingArrival());
    }

    #[Test]
    public function rujukan_yang_ditolak_tidak_bisa_ditandai_tiba(): void
    {
        $masuk = $this->rujukanMasuk();
        $ditolak = $this->sisrute->respond($masuk, accepted: false, reason: 'Penuh.');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah disanggupi');

        $this->sisrute->markArrived($ditolak, 1);
    }

    #[Test]
    public function rujukan_masuk_hanya_bisa_dijawab_sekali(): void
    {
        $masuk = $this->rujukanMasuk();
        $this->sisrute->respond($masuk, accepted: true);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah dijawab');

        $this->sisrute->respond($masuk->refresh(), accepted: false, reason: 'Berubah pikiran.');
    }

    /** Tempat yang disanggupi menahan kapasitas nyata — harus terlihat. */
    #[Test]
    public function rujukan_yang_disanggupi_tapi_belum_tiba_muncul_di_daftar_menunggu(): void
    {
        $masuk = $this->rujukanMasuk();

        $this->assertCount(1, $this->sisrute->unanswered());

        $this->sisrute->respond($masuk, accepted: true);

        $this->assertCount(0, $this->sisrute->unanswered());
        $this->assertCount(1, $this->sisrute->awaitingArrival());
    }

    // -------------------------------------------------------------- referensi

    #[Test]
    public function referensi_sisrute_tersimpan_lewat_mekanisme_yang_sama(): void
    {
        $jumlah = $this->sisrute->refreshReferences('alasan-rujuk');

        $this->assertSame(3, $jumlah);
        $this->assertSame(3, PayerReference::query()
            ->where('payer', SisruteReferralService::SISTEM)
            ->where('reference_type', 'alasan-rujuk')
            ->count());
    }

    #[Test]
    public function jenis_referensi_sisrute_yang_asing_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->sisrute->refreshReferences('golongan-darah');
    }

    // ------------------------------------------------------------------ bantu

    private function rujukanKeluar(string $kodeTujuan = 'RS-TUJUAN-01'): OutgoingReferral
    {
        static $urut = 0;
        $urut++;

        $registrasi = $this->daftarkan();

        return OutgoingReferral::query()->create([
            'referral_number' => 'RJK-' . str_pad((string) $urut, 4, '0', STR_PAD_LEFT),
            'registration_id' => $registrasi->id,
            'patient_id' => $registrasi->patient_id,
            'patient_mrn' => $registrasi->patient_mrn,
            'patient_name' => $registrasi->patient_name,
            'destination_facility_name' => 'RSUP Rujukan Nasional',
            'destination_facility_code' => $kodeTujuan,
            'reason' => 'Memerlukan pelayanan kardiologi intervensi.',
            'diagnosis' => 'I21.9 - Infark Miokard Akut',
            'referred_at' => now(),
            'status' => 'aktif',
        ]);
    }

    private function rujukanMasuk(): SisruteReferral
    {
        $this->sisrute->fetchIncoming(now()->subDay()->toDateString(), now()->toDateString(), $this->petugas->id);

        return SisruteReferral::query()->where('direction', SisruteReferral::MASUK)->firstOrFail();
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Sisrute ' . $urut, 'sex' => 'L', 'birth_date' => '1972-06-06',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
