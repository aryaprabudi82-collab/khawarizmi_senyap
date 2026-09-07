<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Operation;
use App\Modules\Clinical\Models\SurgicalSafetyChecklist;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Clinical\Services\SurgicalSafetyService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
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
 * Daftar tilik keselamatan bedah WHO (domain M item C).
 *
 * Yang paling perlu dikunci:
 *
 * 1. URUTAN FASE DITEGAKKAN. Time Out yang tercatat tanpa Sign In berarti
 *    pemeriksaan sebelum pembiusan tidak pernah terjadi — dokumennya rapi
 *    tapi pengamannya tidak ada.
 * 2. HITUNGAN KASA/INSTRUMEN/ALAT TAJAM YANG TIDAK COCOK MENAHAN SIGN OUT.
 *    Meloloskannya adalah persis kejadian yang daftar tilik ini cegah.
 * 3. DILEKATKAN PADA OPERASINYA, bukan pada kunjungan — dua operasi dalam
 *    satu perawatan punya daftar tiliknya masing-masing.
 */
class SurgicalSafetyTest extends TestCase
{
    use RefreshDatabase;

    private SurgicalSafetyService $tilik;
    private User $perawatOk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->tilik = app(SurgicalSafetyService::class);

        $this->perawatOk = User::query()->create([
            'username' => 'uji-perawat-ok', 'name' => 'Ns. Kamar Operasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ urutan fase

    #[Test]
    public function ketiga_fase_tercatat_berurutan(): void
    {
        $operasi = $this->operasi();

        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn(), [], $this->perawatOk);
        $this->tilik->record($operasi, SurgicalSafetyChecklist::TIME_OUT, $this->jawabanTimeOut());
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_OUT, $this->jawabanSignOut());

        $this->assertTrue($this->tilik->isComplete($operasi));
        $this->assertSame(
            SurgicalSafetyChecklist::URUTAN,
            $this->tilik->forOperation($operasi)->pluck('phase')->all()
        );
    }

    /**
     * ATURAN PERTAMA: urutan adalah isi aturannya, bukan tata letak layar.
     */
    #[Test]
    public function time_out_tanpa_sign_in_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak bisa dicatat sebelum');

        $this->tilik->record($this->operasi(), SurgicalSafetyChecklist::TIME_OUT, $this->jawabanTimeOut());
    }

    #[Test]
    public function sign_out_tanpa_time_out_ditolak(): void
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('Time Out');

        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_OUT, $this->jawabanSignOut());
    }

    /** Dua Sign Out berarti kasa dan instrumen dihitung dua kali. */
    #[Test]
    public function satu_fase_tidak_bisa_dicatat_dua_kali(): void
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('sudah dicatat untuk operasi ini');

        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());
    }

    #[Test]
    public function basis_data_menolak_fase_ganda(): void
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());

        $this->expectException(QueryException::class);

        SurgicalSafetyChecklist::query()->create([
            'operation_id' => $operasi->id,
            'registration_id' => $operasi->registration_id,
            'patient_id' => $operasi->patient_id,
            'patient_name' => $operasi->patient_name,
            'phase' => SurgicalSafetyChecklist::SIGN_IN,
            'procedure_name' => $operasi->service_name,
            'answers' => [],
            'performed_at' => now(),
        ]);
    }

    #[Test]
    public function fase_yang_asing_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->tilik->record($this->operasi(), 'briefing', []);
    }

    // ------------------------------------------------------------- Sign Out

    /**
     * ATURAN KEDUA: Sign Out gunanya justru memastikan tidak ada yang
     * tertinggal di dalam tubuh pasien.
     */
    #[Test]
    public function hitungan_kasa_yang_tidak_cocok_menahan_sign_out(): void
    {
        $operasi = $this->siapSignOut();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('mungkin tertinggal di dalam tubuh pasien');

        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_OUT, [
            'verbal_kelengkapan_kasa' => false,
            'verbal_instrumen' => true,
            'verbal_alat_tajam' => true,
        ]);
    }

    #[Test]
    public function hitungan_yang_belum_dijawab_juga_menahan_sign_out(): void
    {
        $operasi = $this->siapSignOut();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('belum dijawab');

        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_OUT, [
            'verbal_kelengkapan_kasa' => true,
            'verbal_instrumen' => true,
        ]);
    }

    #[Test]
    public function sign_out_lolos_saat_seluruh_hitungan_cocok(): void
    {
        $operasi = $this->siapSignOut();

        $hasil = $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_OUT, $this->jawabanSignOut());

        $this->assertSame(SurgicalSafetyChecklist::SIGN_OUT, $hasil->phase);
    }

    // --------------------------------------------------------- lekat operasi

    /**
     * ATURAN KETIGA: dua operasi dalam satu perawatan punya daftar
     * tiliknya masing-masing.
     */
    #[Test]
    public function dua_operasi_satu_kunjungan_punya_daftar_tilik_terpisah(): void
    {
        $registrasi = $this->daftarkan();
        $pertama = $this->operasi($registrasi, 'OPR-APENDEKTOMI');
        $kedua = $this->operasi($registrasi, 'OPR-HERNIOTOMI');

        $this->tilik->record($pertama, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());

        $this->assertCount(1, $this->tilik->forOperation($pertama));
        $this->assertCount(0, $this->tilik->forOperation($kedua));
        $this->assertSame(SurgicalSafetyChecklist::URUTAN, $this->tilik->missingPhases($kedua));
    }

    /** Nama tindakan yang diverifikasi di kamar operasi tidak ikut berubah. */
    #[Test]
    public function nama_tindakan_dibekukan_saat_daftar_tilik_diisi(): void
    {
        $operasi = $this->operasi();
        $tilik = $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());

        $operasi->update(['service_name' => 'Nama tindakan yang diperbaiki']);

        $this->assertSame('Apendektomi', $tilik->refresh()->procedure_name);
    }

    /**
     * Penandaan area operasi ditanyakan DUA KALI — di Sign In dan lagi di
     * Time Out. Itu bukan kelebihan yang perlu dirapikan: pemeriksaan
     * ganda oleh orang berbeda pada saat berbeda memang pengamannya.
     */
    #[Test]
    public function penandaan_area_operasi_tercatat_di_dua_fase(): void
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());
        $this->tilik->record($operasi, SurgicalSafetyChecklist::TIME_OUT, $this->jawabanTimeOut());

        $fase = $this->tilik->forOperation($operasi)->keyBy('phase');

        $this->assertSame('ada', $fase[SurgicalSafetyChecklist::SIGN_IN]->answers['penandaan_area_operasi']);
        $this->assertSame('ada', $fase[SurgicalSafetyChecklist::TIME_OUT]->answers['penandaan_area_operasi']);
    }

    // ---------------------------------------------------------------- audit

    /**
     * "Operasi mana yang daftar tiliknya tidak lengkap" adalah pertanyaan
     * yang selalu muncul saat akreditasi — dan tidak bisa dijawab kalau
     * ketiga fase cuma disimpan terpisah tanpa saling tahu.
     */
    #[Test]
    public function operasi_dengan_daftar_tilik_tidak_lengkap_bisa_dicari(): void
    {
        $lengkap = $this->siapSignOut();
        $this->tilik->record($lengkap, SurgicalSafetyChecklist::SIGN_OUT, $this->jawabanSignOut());

        $setengah = $this->operasi();
        $this->tilik->record($setengah, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());

        $hasil = $this->tilik->incompleteBetween(now()->toDateString(), now()->toDateString());

        $this->assertCount(1, $hasil);
        $this->assertSame($setengah->id, $hasil->first()->operation_id);
        $this->assertSame(
            [SurgicalSafetyChecklist::TIME_OUT, SurgicalSafetyChecklist::SIGN_OUT],
            $hasil->first()->missing
        );
    }

    #[Test]
    public function temuan_yang_menuntut_perhatian_tercatat_terpisah(): void
    {
        $operasi = $this->operasi();

        $tilik = $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn(), [
            'concerns' => 'Alergi lateks — sarung tangan bebas lateks disiapkan.',
        ]);

        $this->assertStringContainsString('lateks', $tilik->concerns);
    }

    // ------------------------------------------------------------------ bantu

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

    private function siapSignOut(): Operation
    {
        $operasi = $this->operasi();
        $this->tilik->record($operasi, SurgicalSafetyChecklist::SIGN_IN, $this->jawabanSignIn());
        $this->tilik->record($operasi, SurgicalSafetyChecklist::TIME_OUT, $this->jawabanTimeOut());

        return $operasi;
    }

    /**
     * Operasi dibuat lewat jalur yang sebenarnya, bukan disisipkan
     * langsung: tarif dan kode layanannya memang harus ada di katalog.
     */
    private function operasi(?Registration $registrasi = null, string $kodeTindakan = 'OPR-APENDEKTOMI'): Operation
    {
        $registrasi ??= $this->daftarkan();

        return app(ClinicalRecordService::class)->recordOperation(
            $registrasi->id,
            $kodeTindakan,
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
            'name' => 'Pasien Bedah ' . $urut, 'sex' => 'L', 'birth_date' => '1980-03-03',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
