<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\BpjsControlLetter;
use App\Modules\Integration\Models\BpjsOutgoingReferral;
use App\Modules\Integration\Services\Bpjs\BpjsReferralClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsReferralClient;
use App\Modules\Integration\Services\Bpjs\ReferralService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rujukan & surat kontrol BPJS (domain L item A).
 *
 * Diuji terhadap FakeBpjsReferralClient, karena kredensial VClaim RSP UI
 * belum ada. Yang bisa dibuktikan di sini adalah PERILAKU SISTEM KITA
 * terhadap tiap bentuk jawaban BPJS — termasuk jawaban gagal. Yang TIDAK
 * bisa dibuktikan adalah bahwa bentuk permintaannya diterima VClaim yang
 * sesungguhnya; itu menuntut verifikasi terhadap sandbox resmi begitu
 * kredensial faskes diterbitkan.
 *
 * Justru karena itu jalur gagal diuji sebanyak jalur mulus: saat
 * kredensial akhirnya ada, yang paling mungkin berbeda adalah bentuk
 * kegagalannya.
 */
class BpjsReferralTest extends TestCase
{
    use RefreshDatabase;

    private ReferralService $rujukan;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        // Ditegaskan eksplisit supaya tes tidak diam-diam memakai klien asli
        // kalau suatu saat kredensial terisi di lingkungan pengujian.
        $this->app->bind(BpjsReferralClient::class, fn () => new FakeBpjsReferralClient());

        $this->rujukan = app(ReferralService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi-rujukan', 'name' => 'Petugas Integrasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- pencarian

    #[Test]
    public function rujukan_ditemukan_dan_hasilnya_disimpan(): void
    {
        $hasil = $this->rujukan->lookup('pcare', 'nomor', '1234567890');

        $this->assertTrue($hasil->found);
        $this->assertSame('1234567890', $hasil->referral_number);
        $this->assertNotNull($hasil->raw_response, 'Jawaban BPJS disimpan apa adanya untuk penelusuran');
    }

    /** Kegagalan ikut dicatat — itu yang paling dibutuhkan saat menelusuri masalah. */
    #[Test]
    public function rujukan_tidak_ditemukan_tetap_meninggalkan_jejak(): void
    {
        $hasil = $this->rujukan->lookup('rs', 'nomor', '9000000001');

        $this->assertFalse($hasil->found);
        $this->assertSame('201', $hasil->response_code);
        $this->assertStringContainsString('tidak ditemukan', $hasil->response_message);
    }

    #[Test]
    public function vclaim_gangguan_dicatat_bukan_dilempar_sebagai_galat(): void
    {
        $hasil = $this->rujukan->lookup('pcare', 'kartu', '8000000001');

        $this->assertFalse($hasil->found);
        $this->assertSame('500', $hasil->response_code);
        $this->assertStringContainsString('gangguan', $hasil->response_message);
    }

    /**
     * Rujukan BPJS berlaku 90 hari. Yang kedaluwarsa dilaporkan di layar
     * pencarian, bukan diloloskan lalu ditolak saat SEP diterbitkan —
     * ketahuan di loket jauh lebih murah daripada saat pasien menunggu.
     */
    #[Test]
    public function rujukan_kedaluwarsa_ditandai_bukan_diloloskan(): void
    {
        $lama = $this->rujukan->lookup('pcare', 'nomor', '7000000001');
        $baru = $this->rujukan->lookup('pcare', 'nomor', '1000000001');

        $this->assertTrue($lama->isExpired(), 'Rujukan 120 hari lewat masa berlaku');
        $this->assertFalse($baru->isExpired());
        $this->assertGreaterThan(ReferralService::MASA_BERLAKU_HARI, $lama->daysOld());
    }

    #[Test]
    public function sumber_dan_cara_pencarian_di_luar_daftar_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Sumber rujukan 'klinik' tidak dikenal");

        $this->rujukan->lookup('klinik', 'nomor', '123');
    }

    #[Test]
    public function cara_pencarian_tidak_dikenal_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Cara pencarian 'nama' tidak dikenal");

        $this->rujukan->lookup('pcare', 'nama', '123');
    }

    // ---------------------------------------------------------- surat kontrol

    #[Test]
    public function surat_kontrol_terbit_dengan_nomor_dari_bpjs(): void
    {
        $surat = $this->terbitkanSurat('SEP-001');

        $this->assertSame(BpjsControlLetter::TERBIT, $surat->status);
        $this->assertStringStartsWith('SK-', $surat->letter_number, 'Nomor berasal dari BPJS, bukan dinomori sendiri');
    }

    /**
     * Satu SEP hanya boleh punya satu surat kontrol berlaku: penerbitan
     * ganda memberi pasien dua jadwal yang sama-sama sah di mata BPJS.
     */
    #[Test]
    public function satu_sep_tidak_boleh_punya_dua_surat_kontrol_berlaku(): void
    {
        $this->terbitkanSurat('SEP-001');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah punya surat kontrol');

        $this->terbitkanSurat('SEP-001');
    }

    /** Surat yang dibatalkan membebaskan SEP-nya untuk diterbitkan ulang. */
    #[Test]
    public function surat_yang_dibatalkan_membebaskan_sepnya(): void
    {
        $surat = $this->terbitkanSurat('SEP-001');
        $this->rujukan->cancelControlLetter($surat, 'pasien menjadwal ulang');

        $baru = $this->terbitkanSurat('SEP-001');

        $this->assertSame(BpjsControlLetter::TERBIT, $baru->status);
    }

    #[Test]
    public function tanggal_kontrol_yang_sudah_lewat_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak boleh sudah lewat');

        $this->rujukan->issueControlLetter([
            'sep_number' => 'SEP-001',
            'card_number' => '0001234567890',
            'planned_date' => now()->subDays(3)->toDateString(),
        ]);
    }

    /** Penerbitan yang GAGAL tetap tercatat berikut pesan dari BPJS. */
    #[Test]
    public function penerbitan_gagal_tetap_meninggalkan_jejak(): void
    {
        $surat = $this->rujukan->issueControlLetter([
            'sep_number' => '8000-GANGGUAN',
            'card_number' => '0001234567890',
            'planned_date' => now()->addDays(7)->toDateString(),
        ]);

        $this->assertSame(BpjsControlLetter::GAGAL, $surat->status);
        $this->assertNull($surat->letter_number);
        $this->assertStringContainsString('gangguan', $surat->response_message);

        $gagal = $this->rujukan->failures(now()->toDateString(), now()->toDateString());
        $this->assertCount(1, $gagal, 'Kegagalan muncul di laporan penelusuran');
    }

    #[Test]
    public function surat_yang_gagal_tidak_bisa_dibatalkan(): void
    {
        $surat = $this->rujukan->issueControlLetter([
            'sep_number' => '8000-GANGGUAN',
            'card_number' => '0001234567890',
            'planned_date' => now()->addDays(7)->toDateString(),
        ]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('yang terbit');

        $this->rujukan->cancelControlLetter($surat, 'coba-coba');
    }

    // ---------------------------------------------------------- rujukan keluar

    #[Test]
    public function rujukan_keluar_terbit_dengan_nomor_dari_bpjs(): void
    {
        $r = $this->rujukan->issueOutgoingReferral([
            'sep_number' => 'SEP-001',
            'card_number' => '0001234567890',
            'target_facility_code' => 'RS-002',
            'target_facility_name' => 'RS Rujukan',
        ]);

        $this->assertSame(BpjsOutgoingReferral::TERBIT, $r->status);
        $this->assertStringStartsWith('RJKO-', $r->referral_number);
        $this->assertFalse($r->is_special);
    }

    /** Rujukan khusus dibedakan kolom, bukan tabel tersendiri. */
    #[Test]
    public function rujukan_khusus_dibedakan_kolom_bukan_tabel(): void
    {
        $r = $this->rujukan->issueOutgoingReferral([
            'sep_number' => 'SEP-002',
            'card_number' => '0001234567890',
            'target_facility_code' => 'RS-003',
            'is_special' => true,
        ]);

        $this->assertTrue($r->is_special);
        $this->assertSame(1, BpjsOutgoingReferral::query()->where('is_special', true)->count());
    }

    #[Test]
    public function rujukan_keluar_tanpa_faskes_tujuan_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('target_facility_code');

        $this->rujukan->issueOutgoingReferral([
            'sep_number' => 'SEP-003',
            'card_number' => '0001234567890',
        ]);
    }

    // ------------------------------------------------------------------ bantu

    private function terbitkanSurat(string $sep): BpjsControlLetter
    {
        return $this->rujukan->issueControlLetter([
            'sep_number' => $sep,
            'card_number' => '0001234567890',
            'member_name' => 'Peserta Uji',
            'planned_date' => now()->addDays(7)->toDateString(),
            'target_poly' => 'INT',
        ], $this->petugas->id);
    }
}
