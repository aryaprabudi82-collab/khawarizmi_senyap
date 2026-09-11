<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Operation;
use App\Modules\Clinical\Models\SurgicalSafetyChecklist;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Clinical\Services\SurgicalSafetyService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\AncillaryReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * kepatuhan_kelengkapan_keselamatan_bedah (domain J).
 *
 * SATU ATURAN YANG MENJADI SELURUH ISI BERKAS INI: penyebutnya adalah
 * SELURUH operasi, bukan operasi yang punya daftar tilik. Selisih antara
 * kedua rumus itu tidak terlihat sampai ada operasi yang daftar tiliknya
 * tidak pernah diisi — dan justru itulah yang dicari indikator akreditasi
 * ini. Rumus yang salah akan melaporkan 100% pada bulan yang seharusnya
 * paling mengkhawatirkan, dan tidak ada yang menganggapnya galat.
 */
class SurgicalSafetyComplianceTest extends TestCase
{
    use RefreshDatabase;

    private AncillaryReportService $penunjang;

    private SurgicalSafetyService $tilik;

    private User $perawatOk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->penunjang = app(AncillaryReportService::class);
        $this->tilik = app(SurgicalSafetyService::class);

        $this->perawatOk = User::query()->create([
            'username' => 'uji-perawat-ok-kepatuhan', 'name' => 'Ns. Kamar Operasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    /**
     * INTI BERKAS INI. Dua operasi, satu lengkap satu tanpa daftar tilik
     * sama sekali: kepatuhannya 50%, bukan 100%.
     */
    #[Test]
    public function operasi_tanpa_daftar_tilik_dihitung_sebagai_tidak_patuh(): void
    {
        $this->lengkap();
        $this->operasi();   // tidak diisi sama sekali

        $hasil = $this->hitung();

        $this->assertSame(2, $hasil->operasi);
        $this->assertSame(1, $hasil->lengkap);
        $this->assertSame(50.0, $hasil->persen);
        $this->assertSame(1, $hasil->tanpa_daftar_tilik);
    }

    #[Test]
    public function fase_yang_hilang_disebut_satu_per_satu(): void
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn(), [], $this->perawatOk);
        $this->tilik->record($operasi, SurgicalSafetyChecklist::TIME_OUT, $this->jawabanTimeOut());

        $hasil = $this->hitung();

        $this->assertSame(0, $hasil->lengkap);
        $this->assertSame(0, $hasil->tanpa_sign_in);
        $this->assertSame(0, $hasil->tanpa_time_out);
        $this->assertSame(1, $hasil->tanpa_sign_out);
    }

    /**
     * Nol operasi berarti kepatuhannya TIDAK TERUKUR. Melaporkannya sebagai
     * 100% adalah pujian atas sesuatu yang tidak pernah terjadi;
     * melaporkannya sebagai 0% adalah tuduhan yang sama tidak berdasarnya.
     */
    #[Test]
    public function tanpa_operasi_persentasenya_null_bukan_nol_atau_seratus(): void
    {
        $hasil = $this->hitung();

        $this->assertSame(0, $hasil->operasi);
        $this->assertNull($hasil->persen);
    }

    /**
     * Daftar tilik yang tidak pernah menemukan apa pun sepanjang periode
     * perlu ditanyakan, jadi jumlahnya ikut dilaporkan.
     */
    #[Test]
    public function daftar_tilik_yang_menyimpan_temuan_ikut_dihitung(): void
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn(), [
            'concerns' => 'Pasien alergi lateks — sarung tangan diganti sebelum induksi.',
        ], $this->perawatOk);

        $hasil = $this->hitung();

        $this->assertSame(1, $hasil->operasi);
        $this->assertSame(1, $hasil->bertemuan);

        // Tapi temuannya tidak membuat daftar tiliknya lengkap.
        $this->assertSame(0, $hasil->lengkap);
    }

    #[Test]
    public function operasi_di_luar_rentang_tidak_ikut_terhitung(): void
    {
        $this->lengkap();

        $kemarin = now()->subDay()->toDateString();
        $hasil = $this->penunjang->surgicalSafetyCompliance($kemarin, $kemarin);

        $this->assertSame(0, $hasil->operasi);
    }

    // ------------------------------------------------------------- pembantu

    private function hitung(): object
    {
        $hari = now()->toDateString();

        return $this->penunjang->surgicalSafetyCompliance($hari, $hari);
    }

    private function lengkap(): Operation
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn(), [], $this->perawatOk);
        $this->tilik->record($operasi, SurgicalSafetyChecklist::TIME_OUT, $this->jawabanTimeOut());
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_OUT, $this->jawabanSignOut());

        return $operasi;
    }

    private function operasi(): Operation
    {
        return app(ClinicalRecordService::class)->recordOperation(
            $this->daftarkan()->id,
            'OPR-APENDEKTOMI',
            'dr. Bedah Uji, Sp.B',
            'umum',
            'OK-1',
            null,
            $this->perawatOk,
        );
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Kepatuhan '.$urut, 'sex' => 'L', 'birth_date' => '1980-03-03',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }

    /** @return array<string, mixed> */
    private function jawabanSignIn(): array
    {
        return [
            'identitas' => 'ya',
            'penandaan_area_operasi' => 'ada',
            'alergi' => 'lateks',
            'resiko_aspirasi' => 'tidak-ada',
            'resiko_kehilangan_darah' => 'tidak-ada',
            'kesiapan_alat_obat_anestesi' => 'lengkap',
        ];
    }

    /** @return array<string, mixed> */
    private function jawabanTimeOut(): array
    {
        return [
            'verbal_identitas' => 'ya',
            'verbal_tindakan' => 'ya',
            'verbal_area_insisi' => 'ya',
            'penandaan_area_operasi' => 'ada',
            'antibiotik_profilaks' => 'ya',
            'nama_antibiotik' => 'Cefazolin 2 g',
        ];
    }

    /** @return array<string, mixed> */
    private function jawabanSignOut(): array
    {
        return [
            'verbal_tindakan' => 'ya',
            'verbal_kelengkapan_kasa' => true,
            'verbal_instrumen' => true,
            'verbal_alat_tajam' => true,
            'kelengkapan_specimen_label' => 'lengkap',
        ];
    }
}
