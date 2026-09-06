<?php

namespace Tests\Feature\Quality;

use App\Modules\Quality\Models\InfectionEvent;
use App\Modules\Quality\Services\HaisSurveillanceService;
use App\Modules\Quality\Services\QualityException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Surveilans HAIs (domain J item E).
 *
 * Yang paling penting diuji di sini bukan pencatatannya, melainkan cara
 * angkanya dilaporkan: HAIs SELALU per 1000 hari-alat, tidak pernah
 * sebagai jumlah mentah. Jumlah mentah membuat bangsal besar selalu
 * terlihat lebih buruk daripada bangsal kecil, padahal bisa jadi justru
 * lebih aman per pasiennya.
 */
class HaisSurveillanceTest extends TestCase
{
    use RefreshDatabase;

    private HaisSurveillanceService $hais;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hais = app(HaisSurveillanceService::class);
    }

    #[Test]
    public function kejadian_infeksi_tercatat_dengan_nomor_berurut(): void
    {
        $a = $this->kejadian('vap', 'ICU');
        $b = $this->kejadian('isk', 'ICU');

        $this->assertStringStartsWith('HAIS-' . now()->format('Y') . '-', $a->event_number);
        $this->assertNotSame($a->event_number, $b->event_number);
    }

    #[Test]
    public function jenis_infeksi_di_luar_daftar_ditolak(): void
    {
        $this->expectException(QualityException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->kejadian('flu-biasa', 'ICU');
    }

    /**
     * Inti item ini: angka HAIs adalah insiden per 1000 hari-alat.
     * Dua kejadian VAP atas 500 hari ventilator = rate 4,0 — bukan 2.
     */
    #[Test]
    public function rate_dihitung_per_seribu_hari_alat_bukan_jumlah_mentah(): void
    {
        $this->kejadian('vap', 'ICU');
        $this->kejadian('vap', 'ICU');

        $this->penyebut('ICU', ['ventilator_days' => 500]);

        $vap = $this->hais->ratesByType($this->hariIni(), $this->hariIni())->firstWhere('jenis', 'vap');

        $this->assertSame(2, $vap->jumlah);
        $this->assertSame(500, $vap->penyebut);
        $this->assertEqualsWithDelta(4.0, (float) $vap->rate, 0.01, '2 / 500 × 1000 = 4,0');
    }

    /**
     * Bangsal besar dengan lebih banyak kejadian bisa justru LEBIH AMAN.
     * Kalau laporan memakai jumlah mentah, kesimpulannya terbalik.
     */
    #[Test]
    public function bangsal_dengan_kejadian_lebih_banyak_bisa_punya_rate_lebih_rendah(): void
    {
        // Bangsal besar: 3 kejadian atas 1500 hari-alat -> rate 2,0
        $this->kejadian('vap', 'ICU Besar');
        $this->kejadian('vap', 'ICU Besar');
        $this->kejadian('vap', 'ICU Besar');
        $this->penyebut('ICU Besar', ['ventilator_days' => 1500]);

        // Bangsal kecil: 1 kejadian atas 100 hari-alat -> rate 10,0
        $this->kejadian('vap', 'ICU Kecil');
        $this->penyebut('ICU Kecil', ['ventilator_days' => 100]);

        $baris = $this->hais->ratesByUnit($this->hariIni(), $this->hariIni(), 'vap')->keyBy('unit_name');

        $this->assertSame(3, $baris['ICU Besar']->jumlah);
        $this->assertSame(1, $baris['ICU Kecil']->jumlah);

        $this->assertEqualsWithDelta(2.0, (float) $baris['ICU Besar']->rate, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $baris['ICU Kecil']->rate, 0.01);

        $this->assertLessThan(
            (float) $baris['ICU Kecil']->rate,
            (float) $baris['ICU Besar']->rate,
            'Bangsal dengan kejadian TERBANYAK justru paling aman per hari-alat'
        );
    }

    /**
     * Penyebut nol berarti "belum dicatat", bukan "tidak ada hari-alat".
     * Rate-nya harus kosong, bukan disamakan dengan jumlah kejadian.
     */
    #[Test]
    public function rate_kosong_kalau_penyebutnya_belum_dicatat(): void
    {
        $this->kejadian('vap', 'ICU');

        $vap = $this->hais->ratesByType($this->hariIni(), $this->hariIni())->firstWhere('jenis', 'vap');

        $this->assertSame(1, $vap->jumlah);
        $this->assertSame(0, $vap->penyebut);
        $this->assertNull($vap->rate, 'Kosong — bukan 1, dan bukan 0');
    }

    #[Test]
    public function penyebut_yang_dihitung_ulang_mengganti_bukan_menambah(): void
    {
        $this->penyebut('ICU', ['ventilator_days' => 100]);
        $this->penyebut('ICU', ['ventilator_days' => 250]);

        $this->assertSame(1, \App\Modules\Quality\Models\DeviceDay::query()->count(), 'Satu baris per bangsal per tanggal');

        $this->kejadian('vap', 'ICU');
        $vap = $this->hais->ratesByType($this->hariIni(), $this->hariIni())->firstWhere('jenis', 'vap');

        $this->assertSame(250, $vap->penyebut, 'Angka terbaru menggantikan, bukan menjumlah jadi 350');
    }

    /** Plebitis & dekubitus bukan infeksi terkait alat — penyebutnya hari-rawat. */
    #[Test]
    public function infeksi_tanpa_alat_memakai_hari_rawat_sebagai_penyebut(): void
    {
        $this->kejadian('dekubitus', 'Bangsal Melati', 'tanpa-alat');
        $this->penyebut('Bangsal Melati', ['patient_days' => 400, 'ventilator_days' => 0]);

        $d = $this->hais->ratesByType($this->hariIni(), $this->hariIni())->firstWhere('jenis', 'dekubitus');

        $this->assertSame('hari-rawat', $d->satuan_penyebut);
        $this->assertSame(400, $d->penyebut);
        $this->assertEqualsWithDelta(2.5, (float) $d->rate, 0.01);
    }

    /** Bangsal yang mencatat penyebut tapi nol kejadian adalah informasi, bukan baris kosong. */
    #[Test]
    public function bangsal_tanpa_kejadian_tetap_muncul_kalau_penyebutnya_tercatat(): void
    {
        $this->penyebut('ICU Bersih', ['ventilator_days' => 300]);

        $baris = $this->hais->ratesByUnit($this->hariIni(), $this->hariIni(), 'vap')->firstWhere('unit_name', 'ICU Bersih');

        $this->assertNotNull($baris, 'Nol kejadian atas 300 hari-alat adalah hasil yang dicari');
        $this->assertSame(0, $baris->jumlah);
        $this->assertEqualsWithDelta(0.0, (float) $baris->rate, 0.01);
    }

    /** Pencatatan yang bolong harus terlihat, bukan menghasilkan angka yang tampak baik. */
    #[Test]
    public function bangsal_berkejadian_tanpa_penyebut_dilaporkan_terpisah(): void
    {
        $this->kejadian('vap', 'ICU Tanpa Catatan');
        $this->kejadian('isk', 'ICU Terdata');
        $this->penyebut('ICU Terdata', ['urinary_catheter_days' => 200]);

        $bolong = $this->hais->unitsWithoutDenominator($this->hariIni(), $this->hariIni());

        $this->assertContains('ICU Tanpa Catatan', $bolong->all());
        $this->assertNotContains('ICU Terdata', $bolong->all());
    }

    #[Test]
    public function hari_tanpa_penyebut_dihitung_dan_dilaporkan(): void
    {
        $this->penyebut('ICU', ['ventilator_days' => 10]);

        $dari = now()->subDays(4)->toDateString();

        $this->assertSame(4, $this->hais->missingDenominatorDays($dari, $this->hariIni()),
            'Lima hari rentang, satu tercatat, empat belum');
    }

    #[Test]
    public function kejadian_dikelompokkan_harian_dan_bulanan(): void
    {
        $this->kejadian('vap', 'ICU');
        $this->kejadian('vap', 'ICU');
        $this->kejadian('isk', 'ICU');

        $harian = $this->hais->dailyEvents($this->hariIni(), $this->hariIni());
        $bulanan = $this->hais->monthlyEvents($this->hariIni(), $this->hariIni());

        $this->assertCount(2, $harian, 'Dua jenis infeksi pada satu tanggal');
        $this->assertSame(3, (int) $harian->sum('jumlah'));
        $this->assertSame(now()->format('Y-m'), $bulanan->first()->bulan);
    }

    #[Test]
    public function penyaring_bangsal_berlaku_pada_seluruh_potongan(): void
    {
        $this->kejadian('vap', 'ICU');
        $this->kejadian('vap', 'HCU');

        $hariIni = $this->hariIni();

        $this->assertSame(2, (int) $this->hais->ratesByType($hariIni, $hariIni)->sum('jumlah'));
        $this->assertSame(1, (int) $this->hais->ratesByType($hariIni, $hariIni, 'ICU')->sum('jumlah'));
        $this->assertSame(1, (int) $this->hais->dailyEvents($hariIni, $hariIni, 'ICU')->sum('jumlah'));
        $this->assertSame(1, (int) $this->hais->monthlyEvents($hariIni, $hariIni, 'ICU')->sum('jumlah'));
        $this->assertCount(1, $this->hais->events($hariIni, $hariIni, 'ICU'));
    }


    // ------------------------------------------------------------------ layar

    #[Test]
    public function layar_hais_hanya_untuk_tim_mutu(): void
    {
        $this->seed([
            \App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder::class,
            \App\Modules\Platform\Database\Seeders\RoleSeeder::class,
        ]);

        $mutu = \App\Modules\Platform\Models\User::query()->create([
            'username' => 'uji-mutu-hais', 'name' => 'Tim Mutu', 'password' => 'password', 'is_active' => true,
        ]);
        $mutu->roles()->attach(\App\Modules\Platform\Models\Role::query()->where('code', 'admin-mutu')->firstOrFail());

        $this->actingAs($mutu)->get(route('quality.hais.index'))->assertOk();

        $kasir = \App\Modules\Platform\Models\User::query()->create([
            'username' => 'uji-kasir-hais', 'name' => 'Kasir', 'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(\App\Modules\Platform\Models\Role::query()->where('code', 'kasir')->firstOrFail());

        $this->actingAs($kasir)->get(route('quality.hais.index'))->assertForbidden();
    }

    /** Layarnya wajib menyatakan bahwa angkanya per 1000 hari-alat, bukan jumlah mentah. */
    #[Test]
    public function layar_hais_menyatakan_dasar_perhitungannya(): void
    {
        $this->seed([
            \App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder::class,
            \App\Modules\Platform\Database\Seeders\RoleSeeder::class,
        ]);

        $mutu = \App\Modules\Platform\Models\User::query()->create([
            'username' => 'uji-mutu-hais2', 'name' => 'Tim Mutu', 'password' => 'password', 'is_active' => true,
        ]);
        $mutu->roles()->attach(\App\Modules\Platform\Models\Role::query()->where('code', 'admin-mutu')->firstOrFail());

        $this->kejadian('vap', 'ICU');

        $this->actingAs($mutu)
            ->get(route('quality.hais.index', ['dari' => $this->hariIni(), 'sampai' => $this->hariIni()]))
            ->assertOk()
            ->assertSee('per 1000 hari-alat, bukan sebagai jumlah kejadian', false)
            ->assertSee('belum mencatat penyebut', false)
            ->assertSee('ICU', false);
    }

    // ------------------------------------------------------------------ bantu

    private function hariIni(): string
    {
        return now()->toDateString();
    }

    private function kejadian(string $jenis, string $unit, ?string $alat = null): InfectionEvent
    {
        static $urut = 0;
        $urut++;

        return $this->hais->recordEvent([
            'patient_id' => $urut,
            'patient_mrn' => 'RM-' . str_pad((string) $urut, 4, '0', STR_PAD_LEFT),
            'patient_name' => 'Pasien HAIs ' . $urut,
            'unit_name' => $unit,
            'infection_type' => $jenis,
            'device' => $alat,
            'onset_on' => $this->hariIni(),
            'clinical_criteria' => 'Demam >38C, kultur positif, sesuai kriteria surveilans',
        ]);
    }

    private function penyebut(string $unit, array $counts): void
    {
        $this->hais->recordDenominator($unit, $this->hariIni(), $counts);
    }
}
