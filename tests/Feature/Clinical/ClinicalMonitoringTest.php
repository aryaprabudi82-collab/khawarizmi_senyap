<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\DengueMonitoring;
use App\Modules\Clinical\Models\GlucoseMonitoring;
use App\Modules\Clinical\Models\Procedure;
use App\Modules\Clinical\Models\ProcedureReport;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalMonitoringService;
use App\Modules\Clinical\Services\ClinicalRecordService;
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
 * Laporan tindakan & pemantauan berkala (domain M item R).
 *
 * Yang paling perlu dikunci:
 *
 * 1. LAPORAN TINDAKAN MELEKAT PADA TINDAKANNYA — laporan_tindakan
 *    Khanza berkunci no_rawat saja.
 * 2. HEMATOKRIT LABORATORIUM DAN POINT-OF-CARE TIDAK DIBANDINGKAN,
 *    karena keputusan DBD bersandar pada kenaikan 20 persen.
 * 3. REAKSI TRANSFUSI YANG TERJADI WAJIB MENYEBUT TANDA DAN TINDAKAN.
 * 4. ANGKA BERBAHAYA DISEBUTKAN, TIDAK DITAHAN saat pencatatan.
 */
class ClinicalMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private ClinicalMonitoringService $pemantauan;

    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->pemantauan = app(ClinicalMonitoringService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-pemantauan', 'name' => 'Ns. Pemantau',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== laporan tindakan

    #[Test]
    public function dua_tindakan_pada_satu_kunjungan_punya_laporan_sendiri_sendiri(): void
    {
        $kunjungan = $this->daftarkan();

        $pertama = $this->tindakan($kunjungan, 'TDK-JAHIT-LUKA');
        $kedua = $this->tindakan($kunjungan, 'TDK-NEBULIZER');

        $laporanPertama = $this->pemantauan->openReport($pertama, [], $this->perawat);
        $laporanKedua = $this->pemantauan->openReport($kedua, [], $this->perawat);

        // laporan_tindakan Khanza berkunci no_rawat saja.
        $this->assertNotSame($laporanPertama->id, $laporanKedua->id);
        $this->assertSame($pertama->id, $laporanPertama->procedure_id);
        $this->assertSame('Jahit Luka (s.d. 5 jahitan)', $laporanPertama->procedure_name);
    }

    #[Test]
    public function laporan_tanpa_kesimpulan_tidak_bisa_difinalkan(): void
    {
        $laporan = $this->pemantauan->openReport($this->tindakan(), [], $this->perawat);
        $this->pemantauan->saveReport($laporan, ['description' => 'Luka dibersihkan dan dijahit 4 simpul.']);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak ditafsirkan siapa pun/');

        $this->pemantauan->finalizeReport($laporan->refresh(), $this->perawat);
    }

    #[Test]
    public function laporan_lengkap_bisa_difinalkan_dan_perubahan_diagnosis_terbaca(): void
    {
        $laporan = $this->pemantauan->openReport($this->tindakan(), [
            'pre_procedure_diagnosis' => 'Vulnus laceratum',
        ], $this->perawat);

        $this->pemantauan->saveReport($laporan, [
            'description' => 'Luka dibersihkan, dijahit 4 simpul dengan benang non-absorbable.',
            'post_procedure_diagnosis' => 'Vulnus laceratum dengan cedera tendon',
            'conclusion' => 'Tendon ekstensor terpotong sebagian, dirujuk ke bedah ortopedi.',
        ]);

        $final = $this->pemantauan->finalizeReport($laporan->refresh(), $this->perawat);

        $this->assertSame(ProcedureReport::FINAL, $final->status);
        $this->assertTrue($final->diagnosisChanged());
    }

    #[Test]
    public function basis_data_menolak_laporan_final_tanpa_kesimpulan(): void
    {
        $laporan = $this->pemantauan->openReport($this->tindakan(), [], $this->perawat);

        $this->expectException(QueryException::class);

        ProcedureReport::query()->whereKey($laporan->id)->update([
            'status' => ProcedureReport::FINAL, 'finalized_at' => now(), 'conclusion' => '',
        ]);
    }

    #[Test]
    public function laporan_final_tidak_bisa_diubah(): void
    {
        $laporan = $this->laporanFinal();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa diubah/');

        $this->pemantauan->saveReport($laporan, ['conclusion' => 'Diubah diam-diam']);
    }

    // ============================================== transfusi

    #[Test]
    public function reaksi_transfusi_wajib_menyebut_tanda_dan_tindakannya(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/reaksi hemolitik yang mengancam nyawa/');

        $this->pemantauan->monitorTransfusion($kunjungan->id, [
            'blood_product' => 'PRC', 'bag_number' => 'KTG-001',
            'phase' => '15-menit', 'reaction_occurred' => true,
        ], $this->perawat);
    }

    #[Test]
    public function reaksi_yang_disebut_tandanya_tetap_menuntut_tindakan(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak membuktikan itu dilakukan/');

        $this->pemantauan->monitorTransfusion($kunjungan->id, [
            'blood_product' => 'PRC', 'bag_number' => 'KTG-002',
            'phase' => '15-menit', 'reaction_occurred' => true,
            'reaction_signs' => ['demam', 'menggigil'],
        ], $this->perawat);
    }

    #[Test]
    public function tanda_berat_bisa_ditandai(): void
    {
        $kunjungan = $this->daftarkan();

        $pemantauan = $this->pemantauan->monitorTransfusion($kunjungan->id, [
            'blood_product' => 'PRC', 'bag_number' => 'KTG-003',
            'phase' => '15-menit', 'reaction_occurred' => true,
            'reaction_signs' => ['nyeri-pinggang', 'urine-gelap', 'gatal'],
            'reaction_severity' => 'berat',
            'action_taken' => 'Transfusi dihentikan, jalur diganti NaCl, dokter dihubungi.',
        ], $this->perawat);

        $this->assertTrue($pemantauan->needsImmediateStop());
        $this->assertEqualsCanonicalizing(
            ['nyeri-pinggang', 'urine-gelap'],
            $pemantauan->severeSigns()
        );
    }

    #[Test]
    public function belum_dinilai_bukan_berarti_tidak_ada_reaksi(): void
    {
        $kunjungan = $this->daftarkan();

        $pemantauan = $this->pemantauan->monitorTransfusion($kunjungan->id, [
            'blood_product' => 'PRC', 'bag_number' => 'KTG-004', 'phase' => 'sebelum',
        ], $this->perawat);

        $this->assertNull($pemantauan->reaction_occurred);
        $this->assertFalse($pemantauan->needsImmediateStop());
    }

    #[Test]
    public function pemantauan_satu_kantong_bisa_dibaca_berurutan(): void
    {
        $kunjungan = $this->daftarkan();

        foreach (['sebelum', '15-menit', 'selesai'] as $i => $tahap) {
            $this->pemantauan->monitorTransfusion($kunjungan->id, [
                'blood_product' => 'PRC', 'bag_number' => 'KTG-005', 'phase' => $tahap,
                'observed_at' => now()->addMinutes($i * 15),
                'reaction_occurred' => false,
            ], $this->perawat);
        }

        $this->assertSame(
            ['sebelum', '15-menit', 'selesai'],
            $this->pemantauan->transfusionTrail('KTG-005')->pluck('phase')->all()
        );
    }

    #[Test]
    public function nomor_kantong_wajib_diisi(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sampai ke donornya/');

        $this->pemantauan->monitorTransfusion($kunjungan->id, [
            'blood_product' => 'PRC', 'bag_number' => '  ', 'phase' => 'sebelum',
        ], $this->perawat);
    }

    #[Test]
    public function tanda_reaksi_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak dikenali: kesemutan/');

        $this->pemantauan->monitorTransfusion($kunjungan->id, [
            'blood_product' => 'PRC', 'bag_number' => 'KTG-006', 'phase' => 'selama',
            'reaction_occurred' => true, 'reaction_signs' => ['demam', 'kesemutan'],
            'action_taken' => 'Dihentikan.',
        ], $this->perawat);
    }

    // ============================================== DBD

    #[Test]
    public function hematokrit_dari_sumber_berbeda_tidak_dibandingkan(): void
    {
        $kunjungan = $this->daftarkan();

        $lab = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::LABORATORIUM, 'order_id' => 1,
            'haematocrit_percent' => 38.0, 'observed_at' => now()->subHours(6),
        ], $this->perawat);

        $poc = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::POINT_OF_CARE,
            'haematocrit_percent' => 47.0, 'observed_at' => now(),
        ], $this->perawat);

        // 38 -> 47 adalah kenaikan 24 persen, tapi alatnya berbeda. Yang
        // dikembalikan null, bukan angka — perbandingan lintas alat tidak
        // bisa dipertanggungjawabkan pada keputusan sepenting ini.
        $this->assertNull($poc->haematocritRiseFrom($lab));
        $this->assertNull($poc->isPlasmaLeakageFrom($lab));
    }

    #[Test]
    public function kenaikan_hematokrit_dihitung_dari_sumber_yang_sama(): void
    {
        $kunjungan = $this->daftarkan();

        $awal = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::LABORATORIUM, 'order_id' => 1,
            'haematocrit_percent' => 40.0, 'observed_at' => now()->subHours(12),
        ], $this->perawat);

        $lanjut = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::LABORATORIUM, 'order_id' => 2,
            'haematocrit_percent' => 49.0, 'observed_at' => now(),
        ], $this->perawat);

        // 40 -> 49 adalah kenaikan 22,5 persen: melewati ambang 20 persen.
        $this->assertEqualsWithDelta(0.225, $lanjut->haematocritRiseFrom($awal), 0.001);
        $this->assertTrue($lanjut->isPlasmaLeakageFrom($awal));
    }

    #[Test]
    public function nilai_laboratorium_wajib_menunjuk_permintaannya(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sumbernya point-of-care/');

        $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::LABORATORIUM,
            'haematocrit_percent' => 40.0,
        ], $this->perawat);
    }

    #[Test]
    public function basis_data_menolak_nilai_laboratorium_tanpa_permintaan(): void
    {
        $kunjungan = $this->daftarkan();
        $pemantauan = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::POINT_OF_CARE, 'haematocrit_percent' => 40.0,
        ], $this->perawat);

        $this->expectException(QueryException::class);

        DengueMonitoring::query()->whereKey($pemantauan->id)
            ->update(['source' => DengueMonitoring::LABORATORIUM]);
    }

    #[Test]
    public function pemantauan_tanpa_satu_pun_nilai_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan pemantauan/');

        $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::POINT_OF_CARE,
            'fluid_therapy' => 'RL 500 cc dalam 1 jam',
        ], $this->perawat);
    }

    #[Test]
    public function trombosit_rendah_bisa_ditandai(): void
    {
        $kunjungan = $this->daftarkan();

        $rendah = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::POINT_OF_CARE, 'platelets_per_ul' => 45000,
        ], $this->perawat);
        $aman = $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::POINT_OF_CARE, 'platelets_per_ul' => 180000,
            'observed_at' => now()->addMinute(),
        ], $this->perawat);

        $this->assertTrue($rendah->hasLowPlatelets());
        $this->assertFalse($aman->hasLowPlatelets());
    }

    #[Test]
    public function deret_pemantauan_disaring_per_sumber(): void
    {
        $kunjungan = $this->daftarkan();

        $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::LABORATORIUM, 'order_id' => 1,
            'haematocrit_percent' => 40.0,
        ], $this->perawat);
        $this->pemantauan->monitorDengue($kunjungan->id, [
            'source' => DengueMonitoring::POINT_OF_CARE, 'haematocrit_percent' => 46.0,
        ], $this->perawat);

        $this->assertCount(1, $this->pemantauan->dengueTrend($kunjungan->id, DengueMonitoring::LABORATORIUM));
        $this->assertCount(1, $this->pemantauan->dengueTrend($kunjungan->id, DengueMonitoring::POINT_OF_CARE));
    }

    // ============================================== gula darah

    #[Test]
    public function gula_darah_berbahaya_tetap_bisa_dicatat_lebih_dulu(): void
    {
        $kunjungan = $this->daftarkan();

        // Perawat yang menemukan gula darah 45 harus bisa mencatatnya
        // SEKARANG lalu bertindak — bukan ditahan formulir sampai
        // tindakannya selesai diketik.
        $cek = $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'sewaktu', 'glucose_mg_dl' => 45,
        ], $this->perawat);

        $this->assertTrue($cek->isSevereHypoglycaemia());
        $this->assertTrue($cek->isUnactioned());
    }

    #[Test]
    public function angka_berbahaya_tanpa_tindakan_bisa_ditagih(): void
    {
        $kunjungan = $this->daftarkan();

        $tanpaTindakan = $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'sewaktu', 'glucose_mg_dl' => 45,
        ], $this->perawat);

        $ditangani = $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'sewaktu', 'glucose_mg_dl' => 320,
            'insulin' => 'Insulin reguler', 'insulin_dose_unit' => '6 unit subkutan',
            'checked_at' => now()->addMinutes(30),
        ], $this->perawat);

        $tertunggak = $this->pemantauan->unactionedGlucose($kunjungan->id)->pluck('id')->all();

        $this->assertContains($tanpaTindakan->id, $tertunggak);
        $this->assertNotContains($ditangani->id, $tertunggak);
    }

    #[Test]
    public function insulin_wajib_menyebut_dosisnya(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan instruksi yang bisa dijalankan/');

        $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'sebelum-makan', 'glucose_mg_dl' => 280,
            'insulin' => 'Insulin reguler',
        ], $this->perawat);
    }

    #[Test]
    public function basis_data_menolak_insulin_tanpa_dosis(): void
    {
        $kunjungan = $this->daftarkan();
        $cek = $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'sewaktu', 'glucose_mg_dl' => 280,
            'insulin' => 'Insulin reguler', 'insulin_dose_unit' => '4 unit',
        ], $this->perawat);

        $this->expectException(QueryException::class);

        GlucoseMonitoring::query()->whereKey($cek->id)->update(['insulin_dose_unit' => '']);
    }

    #[Test]
    public function angka_gula_darah_yang_mustahil_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/dosis insulin yang salah/');

        $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'sewaktu', 'glucose_mg_dl' => 5000,
        ], $this->perawat);
    }

    #[Test]
    public function gula_darah_normal_tidak_ikut_ditagih(): void
    {
        $kunjungan = $this->daftarkan();

        $normal = $this->pemantauan->recordGlucose($kunjungan->id, [
            'timing' => 'puasa', 'glucose_mg_dl' => 95,
        ], $this->perawat);

        $this->assertFalse($normal->isHypoglycaemia());
        $this->assertFalse($normal->isHyperglycaemia());
        $this->assertFalse($normal->isUnactioned());
    }

    // ---------------------------------------------------------------- fixture

    private function laporanFinal(): ProcedureReport
    {
        $laporan = $this->pemantauan->openReport($this->tindakan(), [], $this->perawat);

        $this->pemantauan->saveReport($laporan, [
            'description' => 'Luka dibersihkan dan dijahit.',
            'conclusion' => 'Luka tertutup baik, tidak ada cedera struktur dalam.',
        ]);

        return $this->pemantauan->finalizeReport($laporan->refresh(), $this->perawat);
    }

    private function tindakan(?Registration $kunjungan = null, string $kode = 'TDK-JAHIT-LUKA'): Procedure
    {
        $kunjungan ??= $this->daftarkan();

        return app(ClinicalRecordService::class)->recordProcedure(
            $kunjungan->id, $kode, 1, null, $this->perawat
        );
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Pemantauan '.$urut, 'sex' => 'L', 'birth_date' => '1983-03-13',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
