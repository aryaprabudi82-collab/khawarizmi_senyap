<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\AccidentRecord;
use App\Modules\Integration\Models\AccidentSupplement;
use App\Modules\Integration\Services\Bpjs\AccidentService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Data induk kecelakaan & penjaminan Jasa Raharja (domain L item K).
 *
 * Yang paling perlu dikunci:
 *
 * 1. "BELUM DITANYAKAN" BUKAN "TIDAK DIJAMIN". Menyamakannya membebankan
 *    tagihan seluruh korban kecelakaan ke BPJS atau ke pasien, padahal
 *    sebagiannya berhak ditanggung Jasa Raharja lebih dulu.
 * 2. SATU KEJADIAN, BANYAK KUNJUNGAN — kunjungan lanjutan adalah suplesi,
 *    bukan kecelakaan baru.
 * 3. TANGGAL KEJADIAN TIDAK BOLEH MELEWATI TANGGAL PELAYANAN. Salah ketik
 *    tahun tetap menerbitkan SEP, lalu klaimnya ditolak berbulan kemudian.
 */
class BpjsAccidentTest extends TestCase
{
    use RefreshDatabase;

    private AccidentService $kecelakaan;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->kecelakaan = app(AccidentService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-kecelakaan', 'name' => 'Petugas IGD Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------ pencatatan kejadian

    #[Test]
    public function kejadian_kecelakaan_tercatat_berikut_lokasi_berjenjangnya(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian(), $this->petugas->id);

        $this->assertSame(AccidentRecord::KLL, $kejadian->accident_type);
        $this->assertSame('dicatat', $kejadian->status);
        $this->assertSame('31', $kejadian->province_code);
        $this->assertTrue($kejadian->involvesJasaRaharja());
    }

    /**
     * Teks bebas membuat laporan kecelakaan per wilayah mustahil disusun,
     * dan VClaim menolaknya saat SEP diterbitkan.
     */
    #[Test]
    public function kecelakaan_lalu_lintas_tanpa_lokasi_berjenjang_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('provinsi, kabupaten/kota, dan kecamatan');

        $this->kecelakaan->record($this->kejadian(['district_code' => null]));
    }

    /** Kecelakaan kerja murni bukan urusan Jasa Raharja — lokasinya tidak wajib. */
    #[Test]
    public function kecelakaan_kerja_tidak_menuntut_lokasi_berjenjang(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian([
            'accident_type' => AccidentRecord::KERJA,
            'province_code' => null, 'regency_code' => null, 'district_code' => null,
        ]));

        $this->assertFalse($kejadian->involvesJasaRaharja());
    }

    /**
     * ATURAN KETIGA: salah ketik tanggal kejadian tetap menerbitkan SEP,
     * lalu klaimnya ditolak berbulan-bulan kemudian.
     */
    #[Test]
    public function tanggal_kejadian_setelah_tanggal_pelayanan_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('setelah tanggal pelayanan');

        $this->kecelakaan->record($this->kejadian([
            'occurred_on' => now()->subDay()->toDateString(),
            'service_date' => now()->subWeek()->toDateString(),
        ]));
    }

    #[Test]
    public function tanggal_kejadian_di_masa_depan_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('melewati hari ini');

        $this->kecelakaan->record($this->kejadian(['occurred_on' => now()->addDay()->toDateString()]));
    }

    #[Test]
    public function jenis_kejadian_asing_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->kecelakaan->record($this->kejadian(['accident_type' => 'terjatuh']));
    }

    // ------------------------------------------------------------ Jasa Raharja

    #[Test]
    public function penjaminan_jasa_raharja_tersimpan_berikut_plafon_dan_nomor_suratnya(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian());

        $dijamin = $this->kecelakaan->askJasaRaharja($kejadian);

        $this->assertTrue($dijamin->jasa_raharja_asked);
        $this->assertTrue($dijamin->jasa_raharja_covered);
        $this->assertSame('dijamin', $dijamin->status);
        $this->assertNotNull($dijamin->jasa_raharja_number);
        $this->assertSame('20000000.00', $dijamin->jasa_raharja_ceiling);
    }

    /**
     * ATURAN PERTAMA: sebelum ditanyakan, jawabannya null — bukan false.
     */
    #[Test]
    public function sebelum_ditanyakan_penjaminan_bernilai_null_bukan_false(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian());

        $this->assertFalse($kejadian->jasa_raharja_asked);
        $this->assertNull($kejadian->jasa_raharja_covered);
    }

    #[Test]
    public function korban_yang_tidak_dijamin_dicatat_terang_terangan(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian(['card_number' => '6001234567890']));

        $hasil = $this->kecelakaan->askJasaRaharja($kejadian);

        $this->assertTrue($hasil->jasa_raharja_asked);
        $this->assertFalse($hasil->jasa_raharja_covered);
        $this->assertSame('tidak-dijamin', $hasil->status);
        $this->assertNull($hasil->jasa_raharja_ceiling);
    }

    /**
     * Panggilan yang gagal bukan jawaban "tidak dijamin" — itu jawaban yang
     * tidak pernah kita terima.
     */
    #[Test]
    public function panggilan_gagal_tidak_dicatat_sebagai_tidak_dijamin(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian(['card_number' => '8001234567890']));

        $hasil = $this->kecelakaan->askJasaRaharja($kejadian);

        $this->assertTrue($hasil->jasa_raharja_asked);
        $this->assertNull($hasil->jasa_raharja_covered);
        $this->assertSame('dicatat', $hasil->status);
    }

    #[Test]
    public function kecelakaan_kerja_tidak_bisa_ditanyakan_ke_jasa_raharja(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian([
            'accident_type' => AccidentRecord::KERJA,
            'province_code' => null, 'regency_code' => null, 'district_code' => null,
        ]));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('hanya menjamin kecelakaan lalu lintas');

        $this->kecelakaan->askJasaRaharja($kejadian);
    }

    /** Daftar inilah yang menunjukkan tagihan siapa yang masih menggantung. */
    #[Test]
    public function kejadian_yang_belum_ditanyakan_muncul_di_daftar_tertunda(): void
    {
        $pertama = $this->kecelakaan->record($this->kejadian());
        $this->kecelakaan->record($this->kejadian());

        $this->assertCount(2, $this->kecelakaan->pendingJasaRaharja());

        $this->kecelakaan->askJasaRaharja($pertama);

        $this->assertCount(1, $this->kecelakaan->pendingJasaRaharja());
    }

    // ------------------------------------------------------------------ suplesi

    /**
     * ATURAN KEDUA: kunjungan lanjutan atas kecelakaan yang sama adalah
     * suplesi, bukan kejadian baru.
     */
    #[Test]
    public function kunjungan_lanjutan_dicatat_sebagai_suplesi_bukan_kejadian_baru(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian(['sep_number' => 'SEP-KLL-0001']));

        $suplesi = $this->kecelakaan->supplement($kejadian, [
            'registration_id' => 991,
            'service_date' => now()->toDateString(),
        ], $this->petugas->id);

        $this->assertSame('diterima', $suplesi->status);
        $this->assertNotNull($suplesi->sep_number);
        $this->assertSame($kejadian->id, $suplesi->accident_record_id);

        // Tetap SATU kejadian.
        $this->assertSame(1, AccidentRecord::query()->count());
        $this->assertSame(1, AccidentSupplement::query()->count());
    }

    #[Test]
    public function satu_kunjungan_tidak_boleh_punya_dua_suplesi(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian(['sep_number' => 'SEP-KLL-0001']));

        $this->kecelakaan->supplement($kejadian, ['registration_id' => 991, 'service_date' => now()->toDateString()]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('ditagihkan dua kali');

        $this->kecelakaan->supplement($kejadian, ['registration_id' => 991, 'service_date' => now()->toDateString()]);
    }

    #[Test]
    public function pelayanan_sebelum_kejadian_ditolak_sebagai_suplesi(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian([
            'occurred_on' => now()->subDay()->toDateString(),
            'sep_number' => 'SEP-KLL-0001',
        ]));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('mendahului tanggal kejadian');

        $this->kecelakaan->supplement($kejadian, [
            'registration_id' => 992,
            'service_date' => now()->subWeek()->toDateString(),
        ]);
    }

    /** Suplesi tanpa SEP awal ditolak BPJS — kegagalannya ikut dicatat. */
    #[Test]
    public function suplesi_tanpa_sep_awal_gagal_dan_kegagalannya_tercatat(): void
    {
        $kejadian = $this->kecelakaan->record($this->kejadian());

        $suplesi = $this->kecelakaan->supplement($kejadian, [
            'registration_id' => 993,
            'service_date' => now()->toDateString(),
        ]);

        $this->assertSame('gagal', $suplesi->status);
        $this->assertStringContainsString('SEP awal', $suplesi->response_message);
    }

    // ------------------------------------------------------------------ bantu

    private function kejadian(array $ubah = []): array
    {
        return array_merge([
            'patient_id' => 1,
            'patient_mrn' => 'RM-000001',
            'patient_name' => 'Korban Uji',
            'card_number' => '0001234567890',
            'accident_type' => AccidentRecord::KLL,
            'occurred_on' => now()->subDays(2)->toDateString(),
            'service_date' => now()->toDateString(),
            'province_code' => '31',
            'regency_code' => '3171',
            'district_code' => '317101',
            'location_note' => 'Jl. Margonda Raya, depan RSP UI',
        ], $ubah);
    }
}
