<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Observation;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ObservationCatalogContext;
use App\Modules\Clinical\Services\ObservationPanelService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panel observasi (domain M item D).
 *
 * Yang paling perlu dikunci:
 *
 * 1. RENTANG RUJUKAN MEMAKAI RENTANG PANEL, bukan rentang bawaan kodenya.
 *    Laju napas 40 normal pada bayi dan gawat pada dewasa; memakai rentang
 *    dewasa untuk panel bayi menyalakan penanda abnormal sepanjang hari,
 *    dan penanda yang selalu menyala melatih orang mengabaikannya.
 * 2. NILAI KOSONG DILEWATI, TIDAK DICATAT SEBAGAI NOL — saturasi oksigen 0
 *    berarti pasien tidak bernapas.
 * 3. MENCATAT ULANG PADA WAKTU YANG SAMA MENGGANTI, bukan menambah baris
 *    kedua: dua nilai suhu pada jam yang sama tidak bisa dibedakan mana
 *    yang berlaku.
 */
class ObservationPanelTest extends TestCase
{
    use RefreshDatabase;

    private ObservationPanelService $panel;
    private ObservationCatalogContext $katalog;
    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->panel = app(ObservationPanelService::class);
        $this->katalog = app(ObservationCatalogContext::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-observasi', 'name' => 'Ns. Observasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------- katalog

    /**
     * 12 kode catatan_observasi_* Khanza jadi 12 panel di sini — unit baru
     * cukup satu baris, bukan migrasi.
     */
    #[Test]
    public function panel_khanza_tersedia_sebagai_data(): void
    {
        $panel = $this->katalog->panels()->pluck('panel_code')->all();

        foreach (['observasi-ranap', 'observasi-igd', 'observasi-bayi', 'observasi-ventilator', 'cek-gds'] as $kode) {
            $this->assertContains($kode, $panel);
        }
    }

    #[Test]
    public function panel_ranap_dan_igd_berisi_pengukuran_yang_sama(): void
    {
        $ranap = $this->katalog->panelItems('observasi-ranap')->keys()->sort()->values()->all();
        $igd = $this->katalog->panelItems('observasi-igd')->keys()->sort()->values()->all();

        $this->assertSame($ranap, $igd);
        $this->assertContains('gcs', $ranap);
        $this->assertContains('saturasi-oksigen', $ranap);
    }

    /**
     * ATURAN PERTAMA, dan ini yang paling menentukan keselamatannya.
     */
    #[Test]
    public function rentang_panel_bayi_berbeda_dari_dewasa(): void
    {
        $dewasa = $this->katalog->panelItems('observasi-ranap')->get('laju-napas');
        $bayi = $this->katalog->panelItems('observasi-bayi')->get('laju-napas');

        $this->assertEquals(12, $dewasa->reference_low);
        $this->assertEquals(20, $dewasa->reference_high);

        $this->assertEquals(30, $bayi->reference_low);
        $this->assertEquals(60, $bayi->reference_high);
    }

    #[Test]
    public function laju_napas_40_normal_pada_bayi_dan_abnormal_pada_dewasa(): void
    {
        $dewasa = $this->katalog->panelItems('observasi-ranap')->get('laju-napas');
        $bayi = $this->katalog->panelItems('observasi-bayi')->get('laju-napas');

        $this->assertTrue($this->katalog->isAbnormal($dewasa, 40.0));
        $this->assertFalse($this->katalog->isAbnormal($bayi, 40.0));
    }

    /**
     * Pengukuran tanpa batas normal universal TIDAK PERNAH ditandai
     * abnormal — bukan ditandai normal diam-diam.
     */
    #[Test]
    public function pengukuran_tanpa_rentang_tidak_pernah_ditandai_abnormal(): void
    {
        $berat = $this->katalog->panelItems('observasi-bayi')->get('berat-badan');

        $this->assertNull($berat->reference_low);
        $this->assertFalse($this->katalog->isAbnormal($berat, 999.0));
    }

    // -------------------------------------------------------------- pencatatan

    #[Test]
    public function panel_tercatat_sebagai_observasi_biasa(): void
    {
        $registrasi = $this->daftarkan();

        $jumlah = $this->panel->record($registrasi->id, 'observasi-ranap', [
            'gcs' => 15,
            'tekanan-darah-sistolik' => 120,
            'nadi' => 88,
            'laju-napas' => 18,
            'suhu' => 36.8,
            'saturasi-oksigen' => 98,
        ], null, $this->perawat);

        $this->assertSame(6, $jumlah);
        $this->assertSame(6, Observation::query()->where('registration_id', $registrasi->id)->count());
    }

    /**
     * Observasi bangsal tidak menuntut asesmen: perawat mencatat tanda
     * vital tiap beberapa jam tanpa sedang membuat asesmen apa pun.
     */
    #[Test]
    public function observasi_bangsal_tidak_menuntut_asesmen(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 37.0]);

        $this->assertNull(Observation::query()->where('registration_id', $registrasi->id)->first()->assessment_id);
    }

    #[Test]
    public function penanda_abnormal_memakai_rentang_panelnya(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-ranap', ['laju-napas' => 40]);
        $this->panel->record($registrasi->id, 'observasi-bayi', ['laju-napas' => 40], now()->addMinute());

        $baris = Observation::query()
            ->where('registration_id', $registrasi->id)
            ->orderBy('observed_at')
            ->get();

        $this->assertTrue($baris[0]->is_abnormal, 'Laju napas 40 pada panel dewasa harus abnormal.');
        $this->assertFalse($baris[1]->is_abnormal, 'Laju napas 40 pada panel bayi harus normal.');
    }

    /**
     * ATURAN KEDUA: yang tidak diukur dan yang hasilnya nol adalah dua hal
     * berbeda.
     */
    #[Test]
    public function nilai_kosong_dilewati_bukan_dicatat_nol(): void
    {
        $registrasi = $this->daftarkan();

        $jumlah = $this->panel->record($registrasi->id, 'observasi-ranap', [
            'suhu' => 37.0,
            'nadi' => null,
            'saturasi-oksigen' => '',
        ]);

        $this->assertSame(1, $jumlah);
        $this->assertSame(0, Observation::query()->where('code', 'nadi')->count());
    }

    /** Nol yang benar-benar diukur TETAP dicatat. */
    #[Test]
    public function nilai_nol_yang_diukur_tetap_dicatat(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-restrain', ['tekanan-darah-sistolik' => 0]);

        $baris = Observation::query()->where('code', 'tekanan-darah-sistolik')->first();

        $this->assertNotNull($baris);
        $this->assertTrue($baris->is_abnormal);
    }

    /**
     * ATURAN KETIGA: dua nilai suhu pada jam yang sama tidak bisa
     * dibedakan mana yang berlaku.
     */
    #[Test]
    public function mencatat_ulang_pada_waktu_yang_sama_mengganti(): void
    {
        $registrasi = $this->daftarkan();
        $waktu = now();

        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 37.0], $waktu);
        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 38.5], $waktu);

        $baris = Observation::query()->where('code', 'suhu')->get();

        $this->assertCount(1, $baris);
        $this->assertSame('38.50', $baris->first()->value_numeric);
    }

    #[Test]
    public function waktu_berbeda_menghasilkan_baris_terpisah(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 37.0], now());
        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 38.5], now()->addHours(4));

        $this->assertSame(2, Observation::query()->where('code', 'suhu')->count());
    }

    #[Test]
    public function pengukuran_di_luar_panel_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak termasuk panel ini');

        $this->panel->record($registrasi->id, 'observasi-ranap', ['ventilator-peep' => 5]);
    }

    #[Test]
    public function panel_yang_asing_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak ada atau sudah tidak aktif');

        $this->panel->record($registrasi->id, 'observasi-luar-angkasa', ['suhu' => 37]);
    }

    /** Nilai teks (mode ventilator) tidak dipaksa jadi angka. */
    #[Test]
    public function nilai_teks_disimpan_sebagai_teks(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-ventilator', [
            'ventilator-mode' => 'SIMV',
            'ventilator-peep' => 5,
        ]);

        $mode = Observation::query()->where('code', 'ventilator-mode')->first();

        $this->assertSame('SIMV', $mode->value_text);
        $this->assertNull($mode->value_numeric);
    }

    // ----------------------------------------------------------------- baca

    /**
     * Bentuk yang dibaca perawat: satu baris per waktu — perburukan
     * terlihat karena tiap waktu berdiri sendiri.
     */
    #[Test]
    public function riwayat_panel_dikelompokkan_per_waktu(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 37.0, 'nadi' => 80], now());
        $this->panel->record($registrasi->id, 'observasi-ranap', ['suhu' => 39.0, 'nadi' => 120], now()->addHours(4));

        $riwayat = $this->panel->timeline($registrasi->id, 'observasi-ranap');

        $this->assertCount(2, $riwayat);
        $this->assertCount(2, $riwayat->first()->values);
    }

    #[Test]
    public function daftar_abnormal_menyaring_yang_di_luar_batas(): void
    {
        $registrasi = $this->daftarkan();

        $this->panel->record($registrasi->id, 'observasi-ranap', [
            'suhu' => 39.5,
            'nadi' => 80,
        ]);

        $abnormal = $this->panel->recentAbnormal($registrasi->id);

        $this->assertCount(1, $abnormal);
        $this->assertSame('suhu', $abnormal->first()->code);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Observasi ' . $urut, 'sex' => 'L', 'birth_date' => '1975-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
