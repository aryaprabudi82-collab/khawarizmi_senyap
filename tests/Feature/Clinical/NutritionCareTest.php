<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\NutritionAssessment;
use App\Modules\Clinical\Models\NutritionNote;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Clinical\Services\NutritionCareService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
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
 * Asuhan gizi (domain M item K).
 *
 * Yang paling perlu dikunci:
 *
 * 1. ALERGEN KEDELAPAN PUNYA TEMPAT. Khanza memberi asuhan_gizi tujuh
 *    kolom alergen tetap; pasien alergi kedelai tidak bisa dicatat, dan
 *    yang tercatat di situ tidak terbaca telaah resep.
 * 2. INDEKS DIHITUNG, dan yang butuh tabel WHO DINYATAKAN BELUM ADA
 *    alih-alih dikarang.
 * 3. AMBANG DEWASA TIDAK DIPAKAI PADA ANAK.
 * 4. CATATAN KOSONG DITOLAK, service dan basis data.
 */
class NutritionCareTest extends TestCase
{
    use RefreshDatabase;

    private NutritionCareService $gizi;

    private User $ahliGizi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->gizi = app(NutritionCareService::class);

        $this->ahliGizi = User::query()->create([
            'username' => 'uji-gizi', 'name' => 'Ahli Gizi Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== alergen bukan kolom tetap

    #[Test]
    public function alergen_di_luar_tujuh_kolom_khanza_bisa_dicatat(): void
    {
        $kunjungan = $this->daftarkan();
        $asuhan = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);

        // Kedelai dan wijen tidak punya kolom di asuhan_gizi Khanza.
        $this->gizi->recordFoodAllergy($asuhan, 'Kedelai', ['reaction' => 'Gatal'], $this->ahliGizi);
        $this->gizi->recordFoodAllergy($asuhan, 'Wijen', ['severity' => 'berat'], $this->ahliGizi);

        $daftar = app(ClinicalRecordService::class)->allergiesFor($kunjungan->patient_id);

        $this->assertCount(2, $daftar);
        $this->assertEqualsCanonicalizing(['Kedelai', 'Wijen'], $daftar->pluck('substance')->all());
        $this->assertSame(['makanan', 'makanan'], $daftar->pluck('category')->all());
    }

    #[Test]
    public function alergi_makanan_ikut_terbaca_daftar_alergi_pasien(): void
    {
        $kunjungan = $this->daftarkan();
        $asuhan = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);

        $this->gizi->recordFoodAllergy($asuhan, 'Udang', ['severity' => 'berat'], $this->ahliGizi);

        // Inti aturannya: telaah resep dan resume medis membaca daftar ini,
        // bukan tujuh kolom milik formulir gizi.
        $this->assertSame(
            'Udang',
            app(ClinicalRecordService::class)->allergiesFor($kunjungan->patient_id)->first()->substance
        );
    }

    #[Test]
    public function hanya_alergi_makanan_yang_dibekukan_ke_asuhan_gizi(): void
    {
        $kunjungan = $this->daftarkan();
        $asuhan = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);

        $this->gizi->recordFoodAllergy($asuhan, 'Telur', [], $this->ahliGizi);
        app(ClinicalRecordService::class)->recordAllergy($kunjungan->patient_id, 'Amoksisilin', ['category' => 'obat']);

        $this->gizi->save($asuhan, ['nutrition_diagnosis' => 'Asupan protein tidak adekuat.']);
        $final = $this->gizi->finalize($asuhan->refresh(), $this->ahliGizi);

        $this->assertCount(1, $final->food_allergies);
        $this->assertSame('Telur', $final->food_allergies[0]['substance']);
    }

    // ================================================== indeks dihitung

    #[Test]
    public function imt_dihitung_bukan_disimpan(): void
    {
        $asuhan = $this->gizi->save($this->buka(), [
            'age_months' => 360, 'sex' => 'L',
            'weight_kg' => 70, 'height_cm' => 170,
        ]);

        // 70 / 1.70^2 = 24.2
        $this->assertSame(24.2, $asuhan->bmi());
        $this->assertSame('berisiko-lebih', $asuhan->bmiCategory());

        // Khanza menyimpan antropometri_imt sebagai kolom yang bisa berbeda
        // dari berat dan tinggi yang melahirkannya.
        $this->assertArrayNotHasKey('bmi', $asuhan->getAttributes());
        $this->assertArrayNotHasKey('antropometri_imt', $asuhan->getAttributes());
    }

    #[Test]
    public function imt_ikut_berubah_saat_beratnya_dikoreksi(): void
    {
        $asuhan = $this->gizi->save($this->buka(), [
            'age_months' => 360, 'sex' => 'L', 'weight_kg' => 70, 'height_cm' => 170,
        ]);

        $dikoreksi = $this->gizi->save($asuhan, ['weight_kg' => 50]);

        // Inilah gunanya tidak disimpan: koreksi berat tidak meninggalkan
        // IMT lama yang salah.
        $this->assertSame(17.3, $dikoreksi->bmi());
        $this->assertSame('kurang', $dikoreksi->bmiCategory());
    }

    #[Test]
    public function berat_badan_ideal_dihitung_menurut_jenis_kelamin(): void
    {
        $lakiLaki = $this->gizi->save($this->buka(), [
            'age_months' => 360, 'sex' => 'L', 'height_cm' => 170,
        ]);
        $perempuan = $this->gizi->save($this->buka(), [
            'age_months' => 360, 'sex' => 'P', 'height_cm' => 170,
        ]);

        // Broca: (170-100) - 10% = 63.0 ; (170-100) - 15% = 59.5
        $this->assertSame(63.0, $lakiLaki->idealWeightKg());
        $this->assertSame(59.5, $perempuan->idealWeightKg());
    }

    #[Test]
    public function berat_badan_ideal_tidak_dihitung_tanpa_jenis_kelamin(): void
    {
        $asuhan = $this->gizi->save($this->buka(), ['age_months' => 360, 'height_cm' => 170]);

        // Rumusnya memang berbeda untuk laki-laki dan perempuan; menebak
        // salah satunya menghasilkan sasaran berat yang salah.
        $this->assertNull($asuhan->idealWeightKg());
    }

    #[Test]
    public function ambang_imt_dewasa_tidak_dipakai_pada_anak(): void
    {
        $anak = $this->gizi->save($this->buka(), [
            'age_months' => 48, 'sex' => 'P', 'weight_kg' => 14, 'height_cm' => 100,
        ]);

        // IMT-nya tetap dihitung, tapi tafsirannya tidak diberikan: anak
        // gemuk dan dewasa gemuk punya ambang yang berbeda.
        $this->assertSame(14.0, $anak->bmi());
        $this->assertNull($anak->bmiCategory());
        $this->assertNull($anak->idealWeightKg());
    }

    #[Test]
    public function indeks_antropometri_anak_dinyatakan_belum_tersedia_bukan_dikarang(): void
    {
        $anak = $this->gizi->save($this->buka(), [
            'age_months' => 30, 'sex' => 'L', 'weight_kg' => 10, 'height_cm' => 85,
        ]);

        // Tabel standar pertumbuhan WHO belum diimpor. Mengarang z-score
        // akan menghasilkan angka yang tampak resmi tapi salah, pada
        // penilaian yang menentukan apakah anak ini gizi buruk.
        $this->assertSame(['BB/U', 'TB/U', 'BB/TB', 'LLA/U'], $anak->pendingChildIndices());
    }

    #[Test]
    public function pasien_dewasa_tidak_menyisakan_indeks_yang_tertunda(): void
    {
        $dewasa = $this->gizi->save($this->buka(), [
            'age_months' => 360, 'sex' => 'L', 'weight_kg' => 70, 'height_cm' => 170,
        ]);

        $this->assertSame([], $dewasa->pendingChildIndices());
    }

    #[Test]
    public function imt_tanpa_pengukuran_lengkap_tidak_dipaksakan(): void
    {
        $asuhan = $this->gizi->save($this->buka(), ['weight_kg' => 70]);

        $this->assertNull($asuhan->bmi());
        $this->assertNull($asuhan->bmiCategory());
    }

    #[Test]
    public function riwayat_berat_bisa_ditrenkan(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Trend Gizi', 'sex' => 'L', 'birth_date' => '1970-01-01',
        ]);

        foreach ([[70, 0], [68, 1], [65, 2]] as [$berat, $mundur]) {
            $kunjungan = $this->daftarkan($pasien, $mundur);
            $asuhan = $this->gizi->open($kunjungan->id, [
                'assessed_on' => now()->subDays($mundur)->toDateString(),
            ], $this->ahliGizi);

            $this->gizi->save($asuhan, [
                'age_months' => 660, 'sex' => 'L', 'weight_kg' => $berat, 'height_cm' => 170,
            ]);
        }

        $trend = $this->gizi->weightTrend($pasien->id);

        // char(5) milik Khanza tidak bisa ditrenkan; justru perubahan berat
        // inilah inti pemantauan gizi.
        $this->assertCount(3, $trend);
        $this->assertSame([65.0, 68.0, 70.0], array_column($trend, 'weight_kg'));
        $this->assertNotNull($trend[0]['bmi']);
    }

    #[Test]
    public function basis_data_menolak_ukuran_yang_mustahil(): void
    {
        $asuhan = $this->buka();

        $this->expectException(QueryException::class);

        NutritionAssessment::query()->whereKey($asuhan->id)->update(['height_cm' => 300]);
    }

    // ================================================== catatan ADIME

    #[Test]
    public function catatan_pemantauan_boleh_mengisi_sebagian_adime(): void
    {
        $kunjungan = $this->daftarkan();

        // monitoring_asuhan_gizi Khanza memang hanya punya dua huruf
        // terakhir ADIME; di sini itu catatan dengan bagian lain kosong.
        $catatan = $this->gizi->addNote($kunjungan->id, [
            'monitoring' => 'Asupan oral 60% dari kebutuhan.',
            'evaluation' => 'Belum mencapai target energi.',
        ], ['kind' => NutritionNote::MONITORING], $this->ahliGizi);

        $this->assertSame(['Monitoring', 'Evaluasi'], $catatan->filledParts());
    }

    #[Test]
    public function catatan_yang_seluruh_bagiannya_kosong_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sebenarnya tidak berisi apa-apa/');

        $this->gizi->addNote($kunjungan->id, [
            'monitoring' => '   ', 'evaluation' => null,
        ], [], $this->ahliGizi);
    }

    #[Test]
    public function basis_data_menolak_catatan_kosong(): void
    {
        $kunjungan = $this->daftarkan();
        $catatan = $this->gizi->addNote($kunjungan->id, ['monitoring' => 'Ada isinya.'], [], $this->ahliGizi);

        $this->expectException(QueryException::class);

        NutritionNote::query()->whereKey($catatan->id)->update(['monitoring' => '']);
    }

    #[Test]
    public function catatan_tidak_boleh_menunjuk_asuhan_pasien_lain(): void
    {
        $satu = $this->daftarkan();
        $lain = $this->daftarkan();
        $asuhanLain = $this->gizi->open($lain->id, [], $this->ahliGizi);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/perkembangan pasien yang salah/');

        $this->gizi->addNote($satu->id, ['monitoring' => 'Asupan membaik.'], [
            'assessment_id' => $asuhanLain->id,
        ], $this->ahliGizi);
    }

    #[Test]
    public function catatan_bisa_menunjuk_asuhan_kunjungan_yang_sama(): void
    {
        $kunjungan = $this->daftarkan();
        $asuhan = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);

        $catatan = $this->gizi->addNote($kunjungan->id, [
            'monitoring' => 'Asupan naik jadi 80%.',
        ], ['assessment_id' => $asuhan->id], $this->ahliGizi);

        $this->assertSame($asuhan->id, $catatan->assessment_id);
        $this->assertCount(1, $asuhan->refresh()->notes);
    }

    // ================================================== finalisasi

    #[Test]
    public function asuhan_tanpa_diagnosis_gizi_tidak_bisa_difinalkan(): void
    {
        $asuhan = $this->buka();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/intervensi gizi tidak punya sasaran/');

        $this->gizi->finalize($asuhan, $this->ahliGizi);
    }

    #[Test]
    public function basis_data_menolak_asuhan_final_tanpa_diagnosis(): void
    {
        $asuhan = $this->buka();

        $this->expectException(QueryException::class);

        NutritionAssessment::query()->whereKey($asuhan->id)->update([
            'status' => NutritionAssessment::FINAL, 'finalized_at' => now(),
        ]);
    }

    #[Test]
    public function asuhan_final_tidak_bisa_disunting(): void
    {
        $asuhan = $this->gizi->save($this->buka(), ['nutrition_diagnosis' => 'Asupan energi kurang.']);
        $final = $this->gizi->finalize($asuhan->refresh(), $this->ahliGizi);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Catat perkembangannya sebagai catatan ADIME/');

        $this->gizi->save($final, ['weight_kg' => 60]);
    }

    #[Test]
    public function satu_kunjungan_satu_asuhan_per_hari(): void
    {
        $kunjungan = $this->daftarkan();

        $pertama = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);
        $kedua = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);

        $this->assertSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function hari_berbeda_adalah_asuhan_berbeda(): void
    {
        $kunjungan = $this->daftarkan();

        $kemarin = $this->gizi->open($kunjungan->id, [
            'assessed_on' => now()->subDay()->toDateString(),
        ], $this->ahliGizi);
        $hariIni = $this->gizi->open($kunjungan->id, [], $this->ahliGizi);

        $this->assertNotSame($kemarin->id, $hariIni->id);
    }

    // ---------------------------------------------------------------- fixture

    private function buka(): NutritionAssessment
    {
        return $this->gizi->open($this->daftarkan()->id, [], $this->ahliGizi);
    }

    private function daftarkan(?object $pasien = null, int $mundurHari = 0): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien ??= app(PatientRegistry::class)->register([
            'name' => 'Pasien Gizi '.$urut, 'sex' => 'L', 'birth_date' => '1972-06-06',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            serviceDate: now()->subDays($mundurHari),
        );
    }
}
