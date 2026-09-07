<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Reporting\Services\ChartCatalog;
use App\Modules\Reporting\Services\ChartService;
use App\Modules\Reporting\Services\ReportingException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grafik & dasbor (domain O item A).
 *
 * 113 kode domain O seluruhnya grafik, dan yang membedakannya cuma
 * tiga hal: dataset apa, dikelompokkan menurut apa, pada satuan waktu
 * apa. Yang dikunci:
 *
 * 1. SUMBU JADI DATA, bukan 113 layar.
 * 2. SUMBU YANG TIDAK DIKENAL DITOLAK, bukan diabaikan diam-diam.
 * 3. NAMA KOLOM TIDAK PERNAH DATANG DARI PEMANGGIL.
 * 4. NILAI KOSONG DIBERI LABEL, tidak dibuang — jumlah batang harus
 *    sama dengan jumlah kejadiannya.
 * 5. KELOMPOK UMUR DIHITUNG TERHADAP TANGGAL PELAYANAN, bukan hari ini.
 */
class ChartTest extends TestCase
{
    use RefreshDatabase;

    private ChartService $grafik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->grafik = app(ChartService::class);
    }

    // ============================================== sumbu jadi data

    #[Test]
    public function satu_dataset_bisa_dikelompokkan_menurut_banyak_sumbu(): void
    {
        $this->daftarkan(['occupation' => 'Petani'], 'POL-UMUM');
        $this->daftarkan(['occupation' => 'Petani'], 'POL-ANAK');
        $this->daftarkan(['occupation' => 'Guru'], 'POL-UMUM');

        // Ketiga grafik Khanza ini — _poli, _perpekerjaan, _percarabayar —
        // adalah kueri yang SAMA dengan GROUP BY berbeda.
        $perUnit = $this->grafik->breakdown('kunjungan', 'unit', ...$this->periode());
        $perPekerjaan = $this->grafik->breakdown('kunjungan', 'pekerjaan', ...$this->periode());

        $this->assertSame(2, collect($perUnit)->firstWhere('label', 'Poliklinik Umum')['value']);
        $this->assertSame(2, collect($perPekerjaan)->firstWhere('label', 'Petani')['value']);
        $this->assertSame(1, collect($perPekerjaan)->firstWhere('label', 'Guru')['value']);
    }

    #[Test]
    public function sumbu_yang_tidak_dikenal_ditolak_bukan_diabaikan(): void
    {
        $this->daftarkan();

        // Grafik yang diam-diam mengabaikan sumbunya akan menampilkan
        // satu batang berisi seluruh data dan terbaca sebagai temuan.
        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches('/terbaca sebagai temuan/');

        $this->grafik->breakdown('kunjungan', 'zodiak', ...$this->periode());
    }

    #[Test]
    public function nama_kolom_tidak_bisa_dititipkan_lewat_sumbu(): void
    {
        $this->daftarkan();

        // Menerima nama kolom dari luar berarti membiarkan pemanggil
        // memilih kolom mana pun dari view yang diterbitkan.
        $this->expectException(ReportingException::class);

        $this->grafik->breakdown('kunjungan', 'p.nik', ...$this->periode());
    }

    #[Test]
    public function dataset_yang_tidak_dikenal_ditolak(): void
    {
        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches("/Dataset 'entah-apa' tidak dikenali/");

        $this->grafik->breakdown('entah-apa', 'unit', ...$this->periode());
    }

    #[Test]
    public function sumbu_yang_tersedia_bisa_didaftar(): void
    {
        $sumbu = $this->grafik->availableDimensions('kunjungan');

        foreach (['unit', 'dokter', 'penjamin', 'pekerjaan', 'pendidikan', 'agama', 'kelompok-umur'] as $kunci) {
            $this->assertArrayHasKey($kunci, $sumbu);
        }
    }

    // ============================================== nilai kosong

    #[Test]
    public function nilai_kosong_diberi_label_bukan_dibuang(): void
    {
        $this->daftarkan(['occupation' => 'Petani']);
        $this->daftarkan(['occupation' => null]);
        $this->daftarkan(['occupation' => '   ']);

        $perPekerjaan = $this->grafik->breakdown('kunjungan', 'pekerjaan', ...$this->periode());

        // Membuangnya membuat jumlah seluruh batang lebih kecil daripada
        // jumlah kunjungan sebenarnya, dan tidak ada yang tahu selisihnya
        // ke mana.
        $this->assertSame(2, collect($perPekerjaan)->firstWhere('label', ChartService::TIDAK_TERCATAT)['value']);
        $this->assertSame(3, array_sum(array_column($perPekerjaan, 'value')));
        $this->assertSame(3, $this->grafik->total('kunjungan', ...$this->periode()));
    }

    #[Test]
    public function jumlah_batang_sama_dengan_jumlah_seluruhnya(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->daftarkan();
        }

        $perUnit = $this->grafik->breakdown('kunjungan', 'unit', ...$this->periode());

        $this->assertSame(
            $this->grafik->total('kunjungan', ...$this->periode()),
            array_sum(array_column($perUnit, 'value'))
        );
    }

    // ============================================== waktu

    #[Test]
    public function deret_waktu_bisa_harian_bulanan_atau_tahunan(): void
    {
        $this->daftarkan([], 'POL-UMUM', 0);
        $this->daftarkan([], 'POL-UMUM', 0);
        $this->daftarkan([], 'POL-UMUM', 40);

        $harian = $this->grafik->overTime('kunjungan', ChartCatalog::HARIAN, ...$this->periode(90));
        $bulanan = $this->grafik->overTime('kunjungan', ChartCatalog::BULANAN, ...$this->periode(90));
        $tahunan = $this->grafik->overTime('kunjungan', ChartCatalog::TAHUNAN, ...$this->periode(90));

        // _pertanggal, _perbulan, dan _pertahun adalah kueri yang sama
        // dengan satuan waktu berbeda.
        $this->assertCount(2, $harian);
        $this->assertCount(2, $bulanan);
        $this->assertCount(1, $tahunan);
        $this->assertSame(3, array_sum(array_column($tahunan, 'value')));
    }

    #[Test]
    public function satuan_waktu_yang_tidak_dikenal_ditolak(): void
    {
        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches('/Satuan waktu .* tidak dikenali/');

        $this->grafik->overTime('kunjungan', 'per-jam', ...$this->periode());
    }

    #[Test]
    public function periode_membatasi_hitungan(): void
    {
        $this->daftarkan([], 'POL-UMUM', 0);
        $this->daftarkan([], 'POL-UMUM', 100);

        $this->assertSame(1, $this->grafik->total('kunjungan', ...$this->periode(7)));
        $this->assertSame(2, $this->grafik->total('kunjungan', ...$this->periode(200)));
    }

    // ============================================== kelompok umur

    #[Test]
    public function kelompok_umur_dihitung_terhadap_tanggal_pelayanan(): void
    {
        // Bayi yang berkunjung dua tahun lalu: umurnya saat itu 20 hari.
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Bayi Dua Tahun Lalu', 'sex' => 'L',
            'birth_date' => now()->subYears(2)->subDays(20)->toDateString(),
        ]);

        // Tanpa dokter: praktisi contoh baru aktif sejak awal tahun ini,
        // dan registrasi memang menolak kunjungan sebelum masa aktifnya.
        $this->daftarkanPasien($pasien, 'POL-ANAK', 730, withPractitioner: false);

        $perUmur = $this->grafik->breakdown('kunjungan', 'kelompok-umur', ...$this->periode(800));

        // Menghitungnya terhadap HARI INI akan memindahkannya ke kelompok
        // balita setiap kali laporan yang sama dijalankan ulang.
        $this->assertSame('0-28 hari', $perUmur[0]['label']);
    }

    #[Test]
    public function tanggal_lahir_yang_kosong_masuk_tidak_diketahui(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Tanpa Tanggal Lahir', 'sex' => 'P', 'birth_date' => null,
        ]);

        $this->daftarkanPasien($pasien);

        $perUmur = $this->grafik->breakdown('kunjungan', 'kelompok-umur', ...$this->periode());

        $this->assertSame('tidak diketahui', $perUmur[0]['label']);
    }

    // ============================================== penyaring

    #[Test]
    public function penyaring_memakai_kunci_sumbu_yang_sama(): void
    {
        $this->daftarkan(['occupation' => 'Petani'], 'POL-UMUM');
        $this->daftarkan(['occupation' => 'Guru'], 'POL-UMUM');
        $this->daftarkan(['occupation' => 'Petani'], 'POL-ANAK');

        $petaniSaja = $this->grafik->breakdown(
            'kunjungan', 'unit', ...array_merge($this->periode(), [['pekerjaan' => 'Petani']])
        );

        $this->assertSame(1, collect($petaniSaja)->firstWhere('label', 'Poliklinik Umum')['value']);
        $this->assertSame(1, collect($petaniSaja)->firstWhere('label', 'Poliklinik Anak')['value']);
    }

    #[Test]
    public function penyaring_di_luar_sumbu_yang_dikenal_ditolak(): void
    {
        $this->daftarkan();

        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches('/bukan sumbu yang dikenal dataset ini/');

        $this->grafik->breakdown(
            'kunjungan', 'unit', ...array_merge($this->periode(), [['nik' => '123']])
        );
    }

    #[Test]
    public function penyaring_kosong_diabaikan_bukan_dianggap_penyaring(): void
    {
        $this->daftarkan();
        $this->daftarkan();

        $tanpaSaring = $this->grafik->breakdown(
            'kunjungan', 'unit', ...array_merge($this->periode(), [['pekerjaan' => null]])
        );

        $this->assertSame(2, array_sum(array_column($tanpaSaring, 'value')));
    }

    // ---------------------------------------------------------------- fixture

    /**
     * @return array{0: string, 1: string}
     */
    private function periode(int $hari = 30): array
    {
        return [now()->subDays($hari)->toDateString(), now()->addDay()->toDateString()];
    }

    private function daftarkan(array $demografi = [], string $unit = 'POL-UMUM', int $mundurHari = 0): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register(array_merge([
            'name' => 'Pasien Grafik '.$urut, 'sex' => 'L', 'birth_date' => '1985-05-05',
        ], $demografi));

        return $this->daftarkanPasien($pasien, $unit, $mundurHari);
    }

    private function daftarkanPasien(
        object $pasien,
        string $unit = 'POL-UMUM',
        int $mundurHari = 0,
        bool $withPractitioner = true,
    ): Registration {
        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', $unit)->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $withPractitioner
                ? Practitioner::query()->where('is_active', true)->value('id')
                : null,
            serviceDate: now()->subDays($mundurHari),
        );
    }
}
