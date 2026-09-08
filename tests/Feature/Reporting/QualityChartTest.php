<?php

namespace Tests\Feature\Reporting;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use App\Modules\Quality\Services\HaisSurveillanceService;
use App\Modules\Quality\Services\IncidentReportService;
use App\Modules\Quality\Services\K3IncidentService;
use App\Modules\Reporting\Services\ChartCatalog;
use App\Modules\Reporting\Services\ChartService;
use App\Modules\Reporting\Services\ReportingException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grafik mutu & keselamatan (domain O item B).
 *
 * Yang dikunci:
 *
 * 1. IKP DAN K3 DIGRAFIKKAN LEWAT MEKANISME YANG SAMA seperti kunjungan
 *    — 15 kode Khanza, nol layar baru.
 * 2. JENIS LUKA BERBEDA DARI JENIS CIDERA, dan kolomnya baru
 *    ditambahkan setelah pembandingan dengan k3rs_peristiwa Khanza.
 * 3. ANGKA GRAFIK SAMA DENGAN ANGKA LAYAR MUTU — dibuktikan, bukan
 *    diasumsikan.
 * 4. SEMBILAN KODE HAIs SENGAJA TIDAK DIDUPLIKASI: sudah dilayani
 *    HaisSurveillanceService sejak domain J item E.
 */
class QualityChartTest extends TestCase
{
    use RefreshDatabase;

    private ChartService $grafik;

    private IncidentReportService $ikp;

    private K3IncidentService $k3;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->grafik = app(ChartService::class);
        $this->ikp = app(IncidentReportService::class);
        $this->k3 = app(K3IncidentService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-grafik-mutu', 'name' => 'Petugas Mutu',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== IKP

    #[Test]
    public function insiden_keselamatan_bisa_digrafikkan_menurut_jenis_dan_dampak(): void
    {
        $this->laporIkp('knc', 'hijau');
        $this->laporIkp('knc', 'kuning');
        $this->laporIkp('ktd', 'merah');

        $perJenis = $this->grafik->breakdown('insiden-keselamatan', 'jenis', ...$this->periode());
        $perDampak = $this->grafik->breakdown('insiden-keselamatan', 'dampak', ...$this->periode());

        // grafik_ikp_jenis dan grafik_ikp_dampak: satu kueri, dua sumbu.
        $this->assertSame(2, collect($perJenis)->firstWhere('label', 'knc')['value']);
        $this->assertSame(1, collect($perDampak)->firstWhere('label', 'merah')['value']);
    }

    #[Test]
    public function insiden_keselamatan_bisa_digrafikkan_menurut_waktu(): void
    {
        $this->laporIkp('knc', 'hijau', 0);
        $this->laporIkp('knc', 'hijau', 0);
        $this->laporIkp('ktd', 'merah', 40);

        $bulanan = $this->grafik->overTime('insiden-keselamatan', ChartCatalog::BULANAN, ...$this->periode(90));
        $tahunan = $this->grafik->overTime('insiden-keselamatan', ChartCatalog::TAHUNAN, ...$this->periode(90));

        // grafik_ikp_pertahun, _perbulan, _pertanggal.
        $this->assertCount(2, $bulanan);
        $this->assertSame(3, array_sum(array_column($tahunan, 'value')));
    }

    #[Test]
    public function uraian_insiden_tidak_ikut_diterbitkan(): void
    {
        $this->laporIkp('ktd', 'merah');

        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='quality' AND table_name='v_incident_summary'"
        );
        $nama = array_column($kolom, 'column_name');

        // Menerbitkan uraian insiden membuka cerita rinci kesalahan
        // kepada siapa pun yang bisa membuka layar laporan.
        foreach (['description', 'root_cause', 'corrective_action', 'immediate_action'] as $rahasia) {
            $this->assertNotContains($rahasia, $nama);
        }
    }

    // ============================================== K3

    #[Test]
    public function jenis_luka_berbeda_dari_jenis_cidera(): void
    {
        // k3rs_peristiwa Khanza membedakan kode_cidera (mekanismenya)
        // dari kode_luka (akibatnya); tabel kita cuma punya yang pertama
        // sampai item ini.
        $this->laporK3(['injury_type' => 'Terjatuh', 'wound_type' => 'Patah tulang']);
        $this->laporK3(['injury_type' => 'Terjatuh', 'wound_type' => 'Memar']);
        $this->laporK3(['injury_type' => 'Tertusuk jarum', 'wound_type' => 'Luka tusuk']);

        $perCidera = $this->grafik->breakdown('k3', 'jenis-cidera', ...$this->periode());
        $perLuka = $this->grafik->breakdown('k3', 'jenis-luka', ...$this->periode());

        $this->assertSame(2, collect($perCidera)->firstWhere('label', 'Terjatuh')['value']);
        $this->assertSame(1, collect($perLuka)->firstWhere('label', 'Patah tulang')['value']);
        $this->assertSame(1, collect($perLuka)->firstWhere('label', 'Memar')['value']);
    }

    #[Test]
    public function insiden_lama_tanpa_jenis_luka_tampil_sebagai_tidak_tercatat(): void
    {
        // Kejadian yang tercatat sebelum kolomnya ada memang tidak punya
        // jawabannya, dan menebaknya lebih buruk daripada mengakui.
        $this->laporK3(['injury_type' => 'Terpapar bahan kimia']);

        $perLuka = $this->grafik->breakdown('k3', 'jenis-luka', ...$this->periode());

        $this->assertSame(ChartService::TIDAK_TERCATAT, $perLuka[0]['label']);
    }

    #[Test]
    public function seluruh_sumbu_k3_khanza_tersedia(): void
    {
        $sumbu = $this->grafik->availableDimensions('k3');

        // Sepuluh kode grafik_k3_* Khanza, nol layar baru.
        foreach ([
            'jenis-cidera', 'jenis-luka', 'dampak-cidera', 'bagian-tubuh',
            'jenis-pekerjaan', 'lokasi', 'penyebab',
        ] as $kunci) {
            $this->assertArrayHasKey($kunci, $sumbu);
        }
    }

    #[Test]
    public function angka_grafik_sama_dengan_angka_layar_mutu(): void
    {
        $this->laporK3(['injury_type' => 'Terjatuh']);
        $this->laporK3(['injury_type' => 'Terjatuh']);
        $this->laporK3(['injury_type' => 'Tertusuk jarum']);

        $rekapMutu = $this->k3->yearlyRecap((int) now()->format('Y'));
        $totalGrafik = $this->grafik->total('k3', ...$this->periodeTahunIni());

        // Dua jalur ke angka yang sama harus SEPAKAT, dan itu dibuktikan
        // di sini alih-alih diasumsikan — kalau suatu saat salah satunya
        // berubah penyaringnya, uji ini yang menangkapnya.
        $this->assertSame($rekapMutu['total'], $totalGrafik);

        $perCidera = $this->grafik->breakdown('k3', 'jenis-cidera', ...$this->periodeTahunIni());
        $this->assertSame(
            $rekapMutu['jenis_cidera']['Terjatuh'],
            collect($perCidera)->firstWhere('label', 'Terjatuh')['value']
        );
    }

    // ============================================== HAIs tidak diduplikasi

    #[Test]
    public function hais_sengaja_tidak_dibuatkan_dataset_grafik(): void
    {
        $dataset = array_keys(ChartCatalog::datasets());

        // Menghitung ulang laju infeksi di sini melahirkan sumber kedua
        // bagi angka infeksi — dan dua angka laju yang berbeda untuk
        // bangsal yang sama jauh lebih buruk daripada satu grafik yang
        // harus dibuka di layar lain.
        $this->assertNotContains('hais', $dataset);

        $dilayaniTempatLain = ChartCatalog::servedElsewhere();
        $this->assertCount(8, $dilayaniTempatLain);
        $this->assertArrayHasKey('grafik_HAIs_laju_vap', $dilayaniTempatLain);
    }

    #[Test]
    public function layanan_hais_memang_menyediakan_seluruh_laju_yang_dijanjikan(): void
    {
        $hais = app(HaisSurveillanceService::class);

        // Janji pada servedElsewhere() harus benar-benar ditepati —
        // kalau tidak, delapan kode itu bukan "dilayani tempat lain"
        // melainkan tidak dilayani siapa pun.
        foreach (['ratesByType', 'ratesByUnit', 'dailyEvents', 'monthlyEvents'] as $method) {
            $this->assertTrue(
                method_exists($hais, $method),
                "HaisSurveillanceService::{$method}() dijanjikan katalog tapi tidak ada."
            );
        }
    }

    // ============================================== batas

    #[Test]
    public function sumbu_ikp_tidak_bisa_dipakai_pada_dataset_k3(): void
    {
        $this->laporK3(['injury_type' => 'Terjatuh']);

        $this->expectException(ReportingException::class);
        $this->expectExceptionMessageMatches("/Sumbu 'dampak' tidak sah untuk dataset 'k3'/");

        $this->grafik->breakdown('k3', 'dampak', ...$this->periode());
    }

    // ---------------------------------------------------------------- fixture

    /**
     * @return array{0: string, 1: string}
     */
    private function periode(int $hari = 30): array
    {
        return [now()->subDays($hari)->toDateString(), now()->addDay()->toDateString()];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodeTahunIni(): array
    {
        return [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()];
    }

    private function laporIkp(string $jenis, string $dampak, int $mundurHari = 0): void
    {
        $this->ikp->report([
            'incident_type' => $jenis,
            'severity_band' => $dampak,
            'occurred_at' => now()->subDays($mundurHari),
            'description' => 'Uraian kejadian yang tidak boleh ikut diterbitkan.',
        ], $this->petugas->id);
    }

    private function laporK3(array $data, int $mundurHari = 0): void
    {
        $this->k3->report(array_merge([
            'occurred_at' => now()->subDays($mundurHari),
            'location' => 'Ruang perawatan lantai 2',
            'body_part' => 'Tangan kanan',
            'injury_impact' => 'Ringan',
            'injury_type' => 'Terjatuh',
            'job_type' => 'Perawat',
            'cause' => 'Lantai licin',
            'description' => 'Terpeleset saat memindahkan pasien.',
        ], $data), $this->petugas->id);
    }
}
