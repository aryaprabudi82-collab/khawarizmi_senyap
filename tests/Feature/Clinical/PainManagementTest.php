<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\PainAssessment;
use App\Modules\Clinical\Models\PainIntervention;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\PainManagementService;
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
 * Pengelolaan nyeri (domain M item O).
 *
 * Yang paling perlu dikunci:
 *
 * 1. LINGKARAN nilai -> tangani -> nilai ulang BISA DITUTUP. Khanza
 *    punya dua kode izin intervensi nyeri tanpa satu pun tabelnya.
 * 2. ALAT UKUR IKUT DICATAT: skor 3 FLACC bukan skor 3 NRS, dan CPOT
 *    bahkan berhenti di 8.
 * 3. NOL ADALAH HASIL PENILAIAN YANG SAH.
 * 4. YANG MEREDAKAN NYERI ADALAH DAFTAR, bukan satu pilihan.
 * 5. INTERVENSI FARMAKOLOGI WAJIB MENYEBUT DOSIS DAN RUTE.
 */
class PainManagementTest extends TestCase
{
    use RefreshDatabase;

    private PainManagementService $nyeri;

    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->nyeri = app(PainManagementService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-nyeri', 'name' => 'Ns. Penilai Nyeri',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== lingkaran tertutup

    #[Test]
    public function nyeri_bisa_dinilai_ditangani_lalu_dinilai_ulang(): void
    {
        $kunjungan = $this->daftarkan();

        $awal = $this->nilai($kunjungan, ['score' => 8, 'kind' => PainAssessment::AKUT]);

        $intervensi = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::FARMAKOLOGI,
            'method' => 'Ketorolak 30 mg',
            'dose' => '30 mg', 'route' => 'Intravena',
        ], $this->perawat);

        $ulang = $this->nilai($kunjungan, [
            'score' => 3, 'kind' => PainAssessment::AKUT,
            'evaluates_intervention_id' => $intervensi->id,
        ]);

        // Inilah yang tidak bisa dilakukan di Khanza: kedua kode izin
        // intervensi nyeri tidak punya tabel sama sekali.
        $this->assertSame($awal->id, $intervensi->assessment_id);
        $this->assertSame($intervensi->id, $ulang->evaluates_intervention_id);
        $this->assertSame($ulang->id, $intervensi->refresh()->evaluation->id);
    }

    #[Test]
    public function perbaikan_nyeri_bisa_dihitung_dari_rantainya(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 8, 'kind' => PainAssessment::AKUT]);

        $intervensi = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::NONFARMAKOLOGI, 'method' => 'Kompres hangat',
        ], $this->perawat);

        $this->nilai($kunjungan, [
            'score' => 2, 'kind' => PainAssessment::AKUT,
            'evaluates_intervention_id' => $intervensi->id,
        ]);

        // 0.8 -> 0.2, jadi berkurang 0.6 dari rentang alat ukurnya.
        $this->assertSame(0.6, $this->nyeri->improvementAfter($intervensi->refresh()));
    }

    #[Test]
    public function intervensi_yang_belum_dinilai_ulang_bisa_ditagih(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 7, 'kind' => PainAssessment::AKUT]);

        $intervensi = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::FARMAKOLOGI, 'method' => 'Parasetamol',
            'dose' => '1 g', 'route' => 'Intravena',
            'given_at' => now()->subHours(3),
        ], $this->perawat);

        $tertunggak = $this->nyeri->awaitingEvaluation($kunjungan->id)->pluck('id')->all();
        $this->assertContains($intervensi->id, $tertunggak);

        $this->nilai($kunjungan, [
            'score' => 2, 'kind' => PainAssessment::AKUT,
            'evaluates_intervention_id' => $intervensi->id,
        ]);

        $this->assertNotContains(
            $intervensi->id,
            $this->nyeri->awaitingEvaluation($kunjungan->id)->pluck('id')->all()
        );
    }

    #[Test]
    public function intervensi_yang_belum_lewat_tenggat_belum_ditagih(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 6, 'kind' => PainAssessment::AKUT]);

        $baru = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::NONFARMAKOLOGI, 'method' => 'Relaksasi napas dalam',
        ], $this->perawat);

        $this->assertFalse($baru->isAwaitingEvaluation());
        $this->assertNotContains($baru->id, $this->nyeri->awaitingEvaluation($kunjungan->id)->pluck('id')->all());
    }

    #[Test]
    public function satu_intervensi_tidak_bisa_dinilai_ulang_dua_kali(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 7, 'kind' => PainAssessment::AKUT]);
        $intervensi = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::NONFARMAKOLOGI, 'method' => 'Reposisi',
        ], $this->perawat);

        $this->nilai($kunjungan, [
            'score' => 4, 'kind' => PainAssessment::AKUT,
            'evaluates_intervention_id' => $intervensi->id,
        ]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah punya penilaian ulang/');

        $this->nilai($kunjungan, [
            'score' => 3, 'kind' => PainAssessment::AKUT,
            'evaluates_intervention_id' => $intervensi->id,
        ]);
    }

    #[Test]
    public function intervensi_pasien_lain_tidak_bisa_dievaluasi(): void
    {
        $satu = $this->daftarkan();
        $lain = $this->daftarkan();

        $awalLain = $this->nilai($lain, ['score' => 7, 'kind' => PainAssessment::AKUT]);
        $intervensiLain = $this->nyeri->intervene($awalLain, [
            'kind' => PainIntervention::NONFARMAKOLOGI, 'method' => 'Kompres',
        ], $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/perbaikan yang tidak pernah terjadi/');

        $this->nilai($satu, [
            'score' => 2, 'kind' => PainAssessment::AKUT,
            'evaluates_intervention_id' => $intervensiLain->id,
        ]);
    }

    // ============================================== alat ukur

    #[Test]
    public function alat_ukur_ikut_tercatat_dan_skornya_dinormalkan(): void
    {
        $kunjungan = $this->daftarkan();

        $dewasa = $this->nilai($kunjungan, [
            'score' => 4, 'kind' => PainAssessment::AKUT, 'scale_type' => 'nrs',
        ]);
        $kritis = $this->nilai($kunjungan, [
            'score' => 4, 'kind' => PainAssessment::AKUT, 'scale_type' => 'cpot',
        ]);

        // Angka 4 yang sama, arti yang berbeda: NRS berhenti di 10, CPOT
        // di 8. Khanza menyimpan keduanya sebagai "4" tanpa alatnya.
        $this->assertSame(0.4, $dewasa->normalisedScore());
        $this->assertSame(0.5, $kritis->normalisedScore());
    }

    #[Test]
    public function skor_di_luar_rentang_alat_ukurnya_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/rentang CPOT/');

        // 9 sah untuk NRS, tidak untuk CPOT yang berhenti di 8.
        $this->nilai($kunjungan, [
            'score' => 9, 'kind' => PainAssessment::AKUT, 'scale_type' => 'cpot',
        ]);
    }

    #[Test]
    public function basis_data_menolak_skor_di_luar_rentang(): void
    {
        $kunjungan = $this->daftarkan();
        $penilaian = $this->nilai($kunjungan, [
            'score' => 4, 'kind' => PainAssessment::AKUT, 'scale_type' => 'cpot',
        ]);

        $this->expectException(QueryException::class);

        PainAssessment::query()->whereKey($penilaian->id)->update(['score' => 9]);
    }

    #[Test]
    public function bps_dimulai_dari_tiga_bukan_nol(): void
    {
        $kunjungan = $this->daftarkan();

        // Pasien terventilasi selalu punya nilai dasar 3 pada BPS.
        $bebasNyeri = $this->nilai($kunjungan, [
            'score' => 3, 'kind' => PainAssessment::TIDAK_ADA, 'scale_type' => 'bps',
        ]);

        $this->assertSame(0.0, $bebasNyeri->normalisedScore());
        $this->assertTrue($bebasNyeri->isPainFree());
    }

    #[Test]
    public function alat_ukur_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'kira-kira' tidak dikenali/");

        $this->nilai($kunjungan, [
            'score' => 4, 'kind' => PainAssessment::AKUT, 'scale_type' => 'kira-kira',
        ]);
    }

    #[Test]
    public function ambang_penanganan_mengikuti_rentang_alat_ukurnya(): void
    {
        $kunjungan = $this->daftarkan();

        // Angka 3 yang sama, kesimpulan yang berbeda — dan justru itu
        // yang tidak bisa dibedakan Khanza. Pada NRS 3 dari 10 masih
        // ringan; pada CPOT 3 dari 8 sudah lebih berat, meski belum
        // mencapai ambang. Yang menentukan rentang alat ukurnya, bukan
        // angka mutlaknya.
        $nrsRingan = $this->nilai($kunjungan, ['score' => 3, 'kind' => PainAssessment::AKUT, 'scale_type' => 'nrs']);
        $cpotSama = $this->nilai($kunjungan, ['score' => 3, 'kind' => PainAssessment::AKUT, 'scale_type' => 'cpot']);

        $this->assertSame(0.3, $nrsRingan->normalisedScore());
        $this->assertSame(0.375, $cpotSama->normalisedScore());
        $this->assertFalse($nrsRingan->needsIntervention());
        $this->assertFalse($cpotSama->needsIntervention());

        // Satu angka lebih tinggi pada CPOT sudah melewati ambang,
        // sementara NRS membutuhkan empat.
        $cpotBerat = $this->nilai($kunjungan, ['score' => 4, 'kind' => PainAssessment::AKUT, 'scale_type' => 'cpot']);
        $nrsSedang = $this->nilai($kunjungan, ['score' => 4, 'kind' => PainAssessment::AKUT, 'scale_type' => 'nrs']);

        $this->assertTrue($cpotBerat->needsIntervention());
        $this->assertTrue($nrsSedang->needsIntervention());
        $this->assertGreaterThan($nrsSedang->normalisedScore(), $cpotBerat->normalisedScore());
    }

    // ============================================== nol & daftar

    #[Test]
    public function nol_adalah_hasil_penilaian_yang_sah(): void
    {
        $kunjungan = $this->daftarkan();

        // Kalau kode memakai empty(), penilaian ini akan ditolak seolah
        // belum diisi — padahal inilah hasil yang dicari setelah nyeri
        // berhasil ditangani.
        $penilaian = $this->nilai($kunjungan, ['score' => 0, 'kind' => PainAssessment::TIDAK_ADA]);

        $this->assertSame(0, $penilaian->score);
        $this->assertTrue($penilaian->isPainFree());
        $this->assertFalse($penilaian->needsIntervention());
    }

    #[Test]
    public function skor_yang_tidak_diisi_sama_sekali_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Nol adalah jawaban yang sah/');

        $this->nyeri->assess($kunjungan->id, [
            'kind' => PainAssessment::AKUT, 'scale_type' => 'nrs',
        ], $this->perawat);
    }

    #[Test]
    public function nyeri_yang_dinyatakan_tidak_ada_tidak_boleh_berskor(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/dinyatakan tidak ada tapi skornya 5/');

        $this->nilai($kunjungan, ['score' => 5, 'kind' => PainAssessment::TIDAK_ADA]);
    }

    #[Test]
    public function basis_data_menolak_tidak_ada_nyeri_yang_berskor(): void
    {
        $kunjungan = $this->daftarkan();
        $penilaian = $this->nilai($kunjungan, ['score' => 0, 'kind' => PainAssessment::TIDAK_ADA]);

        $this->expectException(QueryException::class);

        PainAssessment::query()->whereKey($penilaian->id)->update(['score' => 6]);
    }

    #[Test]
    public function yang_meredakan_nyeri_boleh_lebih_dari_satu(): void
    {
        $kunjungan = $this->daftarkan();

        // Persis kasus yang tidak muat di enum nyeri_hilang Khanza.
        $penilaian = $this->nilai($kunjungan, [
            'score' => 5, 'kind' => PainAssessment::KRONIS,
            'relieved_by' => ['minum-obat', 'perubahan-posisi', 'kompres-hangat'],
        ]);

        $this->assertSame(
            ['minum-obat', 'perubahan-posisi', 'kompres-hangat'],
            $penilaian->relieved_by
        );
    }

    #[Test]
    public function pereda_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak dikenali: berdoa-keras/');

        $this->nilai($kunjungan, [
            'score' => 5, 'kind' => PainAssessment::AKUT,
            'relieved_by' => ['istirahat', 'berdoa-keras'],
        ]);
    }

    #[Test]
    public function menyebar_yang_belum_ditanyakan_bukan_berarti_tidak(): void
    {
        $kunjungan = $this->daftarkan();
        $penilaian = $this->nilai($kunjungan, ['score' => 5, 'kind' => PainAssessment::AKUT]);

        $this->assertNull($penilaian->radiates);

        $ditanya = $this->nilai($kunjungan, [
            'score' => 5, 'kind' => PainAssessment::AKUT, 'radiates' => false,
        ]);

        $this->assertFalse($ditanya->radiates);
    }

    // ============================================== intervensi

    #[Test]
    public function intervensi_farmakologi_wajib_menyebut_dosis_dan_rute(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 7, 'kind' => PainAssessment::AKUT]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa ditelusuri saat nyerinya ternyata tidak berkurang/');

        $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::FARMAKOLOGI, 'method' => 'Analgetik',
        ], $this->perawat);
    }

    #[Test]
    public function intervensi_nonfarmakologi_tidak_menuntut_dosis(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 6, 'kind' => PainAssessment::AKUT]);

        $intervensi = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::NONFARMAKOLOGI,
            'method' => 'Kompres hangat pada perut bawah',
        ], $this->perawat);

        $this->assertNull($intervensi->dose);
        $this->assertFalse($intervensi->isPharmacological());
    }

    #[Test]
    public function basis_data_menolak_farmakologi_tanpa_dosis(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 7, 'kind' => PainAssessment::AKUT]);
        $intervensi = $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::FARMAKOLOGI, 'method' => 'Ketorolak',
            'dose' => '30 mg', 'route' => 'IV',
        ], $this->perawat);

        $this->expectException(QueryException::class);

        PainIntervention::query()->whereKey($intervensi->id)->update(['dose' => '']);
    }

    #[Test]
    public function pasien_yang_dinyatakan_tidak_nyeri_tidak_ditangani(): void
    {
        $kunjungan = $this->daftarkan();
        $bebas = $this->nilai($kunjungan, ['score' => 0, 'kind' => PainAssessment::TIDAK_ADA]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/catat penilaian baru lebih dulu/');

        $this->nyeri->intervene($bebas, [
            'kind' => PainIntervention::NONFARMAKOLOGI, 'method' => 'Kompres',
        ], $this->perawat);
    }

    #[Test]
    public function intervensi_tanpa_menyebut_apa_yang_dikerjakan_ditolak(): void
    {
        $kunjungan = $this->daftarkan();
        $awal = $this->nilai($kunjungan, ['score' => 6, 'kind' => PainAssessment::AKUT]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak bisa dievaluasi maupun diulang/');

        $this->nyeri->intervene($awal, [
            'kind' => PainIntervention::NONFARMAKOLOGI, 'method' => '   ',
        ], $this->perawat);
    }

    #[Test]
    public function perjalanan_nyeri_satu_kunjungan_bisa_dibaca_berurutan(): void
    {
        $kunjungan = $this->daftarkan();

        $this->nilai($kunjungan, ['score' => 8, 'kind' => PainAssessment::AKUT, 'assessed_at' => now()->subHours(4)]);
        $this->nilai($kunjungan, ['score' => 5, 'kind' => PainAssessment::AKUT, 'assessed_at' => now()->subHours(2)]);
        $this->nilai($kunjungan, ['score' => 2, 'kind' => PainAssessment::AKUT, 'assessed_at' => now()]);

        $this->assertSame([8, 5, 2], $this->nyeri->timelineFor($kunjungan->id)->pluck('score')->all());
    }

    // ---------------------------------------------------------------- fixture

    private function nilai(Registration $kunjungan, array $data): PainAssessment
    {
        return $this->nyeri->assess($kunjungan->id, $data + [
            'scale_type' => 'nrs',
            'location' => 'Perut kanan bawah',
        ], $this->perawat);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Nyeri '.$urut, 'sex' => 'P', 'birth_date' => '1988-11-11',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
