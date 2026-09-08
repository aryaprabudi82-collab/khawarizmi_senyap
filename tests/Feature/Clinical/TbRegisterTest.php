<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\TbCase;
use App\Modules\Clinical\Models\TbFollowup;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\TbRegisterService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\ChartService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Register program TB (domain O item E).
 *
 * Yang paling perlu dikunci:
 *
 * 1. "SEMBUH" MENUNTUT BUKTI BAKTERIOLOGIS NEGATIF pada akhir
 *    pengobatan — pembeda satu-satunya dari "pengobatan lengkap", dan
 *    angka kesembuhan nasional dihitung dari yang pertama saja.
 * 2. "TIDAK DILAKUKAN" BUKAN "NEGATIF".
 * 3. SKORING ANAK HANYA UNTUK ANAK.
 * 4. STATUS HIV "TIDAK DIKETAHUI" ADALAH JAWABAN YANG SAH.
 * 5. NAMA PASIEN TIDAK IKUT DITERBITKAN ke kontrak grafik.
 */
class TbRegisterTest extends TestCase
{
    use RefreshDatabase;

    private TbRegisterService $tb;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->tb = app(TbRegisterService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-tb', 'name' => 'Petugas Program TB',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== sembuh vs lengkap

    #[Test]
    public function sembuh_menuntut_bukti_bakteriologis_negatif(): void
    {
        $kasus = $this->daftarkanKasus();

        // Pengobatan selesai, tapi tidak ada pemeriksaan akhir.
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/melebih-lebihkan keberhasilan program/');

        $this->tb->close($kasus, TbCase::SEMBUH);
    }

    #[Test]
    public function pengobatan_lengkap_tidak_menuntut_bukti(): void
    {
        $kasus = $this->daftarkanKasus();

        // Keduanya sama-sama berarti pengobatan selesai; bedanya cuma
        // ada tidaknya bukti bakteriologis.
        $ditutup = $this->tb->close($kasus, TbCase::PENGOBATAN_LENGKAP);

        $this->assertSame(TbCase::PENGOBATAN_LENGKAP, $ditutup->outcome);
        $this->assertNotNull($ditutup->outcome_on);
    }

    #[Test]
    public function sembuh_diterima_bila_pemeriksaan_akhir_negatif(): void
    {
        $kasus = $this->daftarkanKasus();

        $this->tb->recordFollowup($kasus, TbFollowup::AKHIR_PENGOBATAN, ['smear_result' => 'negatif']);

        $ditutup = $this->tb->close($kasus->refresh(), TbCase::SEMBUH);

        $this->assertSame(TbCase::SEMBUH, $ditutup->outcome);
    }

    #[Test]
    public function tidak_dilakukan_bukan_negatif(): void
    {
        $kasus = $this->daftarkanKasus();

        // Pemeriksaan yang tidak pernah dikerjakan tidak boleh terbaca
        // sebagai bukti kesembuhan.
        $this->tb->recordFollowup($kasus, TbFollowup::AKHIR_PENGOBATAN, ['smear_result' => 'tidak-dilakukan']);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/hasil NEGATIF/');

        $this->tb->close($kasus->refresh(), TbCase::SEMBUH);
    }

    #[Test]
    public function pemeriksaan_akhir_yang_masih_positif_tidak_membuat_sembuh(): void
    {
        $kasus = $this->daftarkanKasus();
        $this->tb->recordFollowup($kasus, TbFollowup::AKHIR_PENGOBATAN, ['smear_result' => '2+']);

        $this->expectException(ClinicalException::class);

        $this->tb->close($kasus->refresh(), TbCase::SEMBUH);
    }

    #[Test]
    public function kasus_yang_lolos_lewat_jalan_lain_tetap_bisa_ditagih(): void
    {
        $kasus = $this->daftarkanKasus();

        // Service bukan satu-satunya pintu ke tabel, dan pada angka yang
        // dipakai perencanaan nasional satu lapis saja tidak cukup.
        TbCase::query()->whereKey($kasus->id)->update([
            'outcome' => TbCase::SEMBUH, 'outcome_on' => now()->toDateString(),
        ]);

        $temuan = $this->tb->auditCureClaims()->pluck('id')->all();

        $this->assertContains($kasus->id, $temuan);
    }

    #[Test]
    public function kasus_yang_sudah_ditutup_tidak_bisa_ditutup_lagi(): void
    {
        $kasus = $this->daftarkanKasus();
        $this->tb->close($kasus, TbCase::PENGOBATAN_LENGKAP);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah punya hasil akhir/');

        $this->tb->close($kasus->refresh(), TbCase::MENINGGAL);
    }

    #[Test]
    public function basis_data_menolak_hasil_akhir_tanpa_tanggal(): void
    {
        $kasus = $this->daftarkanKasus();

        $this->expectException(QueryException::class);

        TbCase::query()->whereKey($kasus->id)->update(['outcome' => TbCase::MENINGGAL]);
    }

    // ============================================== skoring anak

    #[Test]
    public function skoring_anak_ditolak_pada_pasien_dewasa(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/pemeriksaan dahak yang seharusnya dikerjakan tidak dikerjakan/');

        $this->daftarkanKasus(['age_years' => 32, 'child_score' => 7]);
    }

    #[Test]
    public function skoring_anak_diterima_pada_anak(): void
    {
        $kasus = $this->daftarkanKasus(['age_years' => 4, 'child_score' => 7]);

        $this->assertSame(7, $kasus->child_score);
        $this->assertTrue($kasus->isChild());
    }

    #[Test]
    public function skoring_tanpa_umur_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tanpa umur tidak ada yang bisa memastikan itu/');

        $this->daftarkanKasus(['age_years' => null, 'child_score' => 6]);
    }

    #[Test]
    public function basis_data_menolak_skor_di_luar_rentang(): void
    {
        $kasus = $this->daftarkanKasus(['age_years' => 4, 'child_score' => 7]);

        $this->expectException(QueryException::class);

        TbCase::query()->whereKey($kasus->id)->update(['child_score' => 20]);
    }

    // ============================================== status HIV

    #[Test]
    public function status_hiv_tidak_diketahui_adalah_jawaban_yang_sah(): void
    {
        $kasus = $this->daftarkanKasus();

        // Pasien TB yang belum dites berbeda dari yang hasilnya
        // non-reaktif, dan justru yang pertama yang harus ditawari tes.
        $this->assertSame('tidak-diketahui', $kasus->hiv_status);
        $this->assertTrue($kasus->needsHivTest());

        $this->assertContains($kasus->id, $this->tb->awaitingHivTest()->pluck('id')->all());
    }

    #[Test]
    public function pasien_yang_sudah_dites_tidak_ikut_ditagih(): void
    {
        $kasus = $this->daftarkanKasus([
            'hiv_status' => 'negatif',
            'hiv_tested_on' => now()->subWeek()->toDateString(),
            'hiv_test_result' => 'non-reaktif',
        ]);

        $this->assertFalse($kasus->needsHivTest());
        $this->assertNotContains($kasus->id, $this->tb->awaitingHivTest()->pluck('id')->all());
    }

    // ============================================== rekap & grafik

    #[Test]
    public function rekap_memisahkan_sembuh_dari_pengobatan_lengkap(): void
    {
        $sembuh = $this->daftarkanKasus();
        $this->tb->recordFollowup($sembuh, TbFollowup::AKHIR_PENGOBATAN, ['smear_result' => 'negatif']);
        $this->tb->close($sembuh->refresh(), TbCase::SEMBUH);

        $lengkap = $this->daftarkanKasus();
        $this->tb->close($lengkap, TbCase::PENGOBATAN_LENGKAP);

        $putus = $this->daftarkanKasus();
        $this->tb->close($putus, TbCase::PUTUS_BEROBAT);

        $rekap = $this->tb->outcomeRecap($sembuh->report_year, $sembuh->report_quarter);

        // Laporannya memang menuntut keduanya terlihat sendiri-sendiri.
        $this->assertSame(1, $rekap[TbCase::SEMBUH]);
        $this->assertSame(1, $rekap[TbCase::PENGOBATAN_LENGKAP]);
        $this->assertSame(3, $rekap['total']);
        $this->assertEqualsWithDelta(66.67, $rekap['angka_keberhasilan'], 0.01);
    }

    #[Test]
    public function register_tb_bisa_digrafikkan_lewat_mekanisme_yang_sama(): void
    {
        $this->daftarkanKasus(['treatment_history' => 'baru']);
        $this->daftarkanKasus(['treatment_history' => 'baru']);
        $this->daftarkanKasus(['treatment_history' => 'kambuh']);

        $grafik = app(ChartService::class);
        $perRiwayat = $grafik->breakdown(
            'tb', 'riwayat', now()->subDays(30)->toDateString(), now()->addDay()->toDateString()
        );

        // 11 kode grafik_tb_*, nol layar baru.
        $this->assertSame(2, collect($perRiwayat)->firstWhere('label', 'baru')['value']);
        $this->assertSame(1, collect($perRiwayat)->firstWhere('label', 'kambuh')['value']);
    }

    #[Test]
    public function nama_pasien_tidak_ikut_diterbitkan_ke_kontrak_grafik(): void
    {
        $kolom = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema='clinical' AND table_name='v_tb_case'"
        );
        $nama = array_column($kolom, 'column_name');

        // Register TB yang bisa dibaca per nama lewat layar laporan
        // adalah daftar pengidap yang beredar di luar keperluannya.
        foreach (['patient_name', 'patient_mrn', 'patient_id', 'register_number'] as $identitas) {
            $this->assertNotContains($identitas, $nama);
        }

        // Status HIV tetap ikut — program nasional mewajibkannya.
        $this->assertContains('hiv_status', $nama);
    }

    #[Test]
    public function klasifikasi_di_luar_kosakata_program_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Riwayat pengobatan .* tidak dikenali/');

        $this->daftarkanKasus(['treatment_history' => 'entah-bagaimana']);
    }

    // ---------------------------------------------------------------- fixture

    private function daftarkanKasus(array $data = []): TbCase
    {
        $kunjungan = $this->daftarkan();

        return $this->tb->register($kunjungan->patient_id, array_merge([
            'registration_id' => $kunjungan->id,
            'diagnosis_type' => 'terkonfirmasi-bakteriologis',
            'anatomical_site' => 'paru',
            'treatment_history' => 'baru',
            'age_years' => 30,
            'treatment_started_on' => now()->subMonths(6)->toDateString(),
            'regimen' => '2(HRZE)/4(HR)3',
            'drug_source' => 'program-tb',
        ], $data), $this->petugas);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien TB '.$urut, 'sex' => 'L', 'birth_date' => '1992-02-02',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-PD')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
