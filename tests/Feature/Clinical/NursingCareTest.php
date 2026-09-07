<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\Catalog\Models\NursingProblem;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Services\CatalogException;
use App\Modules\Catalog\Services\FormTemplateService;
use App\Modules\Catalog\Services\NursingCareMasterService;
use App\Modules\Clinical\Models\FormResponse;
use App\Modules\Clinical\Models\NursingCarePlanItem;
use App\Modules\Clinical\Models\NursingDiagnosis;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\FormResponseService;
use App\Modules\Clinical\Services\NursingCareService;
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
 * Asuhan keperawatan (domain M item B).
 *
 * Yang paling perlu dikunci:
 *
 * 1. RENCANA HARUS BERADA DI BAWAH MASALAH YANG DITEGAKKAN. Di Khanza,
 *    tabel rencana adalah anak langsung lembar asesmen sehingga rencana
 *    bisa dipilih tanpa masalahnya — intervensi tanpa indikasi. Master
 *    Khanza sendiri sudah memasang foreign key rencana → masalah, jadi
 *    yang dikerjakan di sini menegakkan aturan Khanza, bukan menyimpang.
 * 2. NAMA MASALAH DAN BUNYI RENCANA DIBEKUKAN saat dipilih.
 * 3. ASESMEN YANG SUDAH FINAL TIDAK BISA DITAMBAHI ASUHAN.
 */
class NursingCareTest extends TestCase
{
    use RefreshDatabase;

    private NursingCareMasterService $master;
    private NursingCareService $asuhan;
    private FormResponseService $formulir;
    private User $perawat;
    private NursingProblem $nyeriAkut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->master = app(NursingCareMasterService::class);
        $this->asuhan = app(NursingCareService::class);
        $this->formulir = app(FormResponseService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-asuhan', 'name' => 'Ns. Uji',
            'password' => 'password', 'is_active' => true,
        ]);

        app(FormTemplateService::class)->create([
            'code' => 'asesmen-keperawatan-ralan',
            'name' => 'Awal Keperawatan Ralan Umum',
            'category' => FormTemplate::ASESMEN_KEPERAWATAN,
            'sections' => [['title' => 'Keluhan', 'questions' => [
                ['key' => 'keluhan', 'label' => 'Keluhan utama', 'type' => 'text'],
            ]]],
        ]);

        $this->nyeriAkut = $this->master->addProblem([
            'code' => 'D01', 'name' => 'Nyeri Akut', 'standard_code' => 'D.0077',
        ]);

        $this->master->addCarePlan($this->nyeriAkut, [
            'code' => 'R01', 'plan' => 'Identifikasi lokasi, karakteristik, dan intensitas nyeri.',
            'standard_code' => 'I.08238',
        ]);
        $this->master->addCarePlan($this->nyeriAkut, [
            'code' => 'R02', 'plan' => 'Ajarkan teknik nonfarmakologis untuk mengurangi nyeri.',
        ]);
    }

    // ---------------------------------------------------------------- master

    #[Test]
    public function rencana_selalu_berada_di_bawah_masalahnya(): void
    {
        $rencana = $this->master->carePlansFor($this->nyeriAkut);

        $this->assertCount(2, $rencana);
        $this->assertSame($this->nyeriAkut->id, $rencana->first()->nursing_problem_id);
    }

    #[Test]
    public function kode_masalah_yang_sama_boleh_dipakai_di_spesialisasi_berbeda(): void
    {
        $anak = $this->master->addProblem([
            'code' => 'D01', 'name' => 'Nyeri Akut pada Anak', 'specialty' => 'anak',
        ]);

        $this->assertNotSame($this->nyeriAkut->id, $anak->id);
        $this->assertSame('anak', $anak->specialty);
    }

    #[Test]
    public function kode_masalah_ganda_dalam_spesialisasi_yang_sama_ditolak(): void
    {
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('sudah ada untuk spesialisasi umum');

        $this->master->addProblem(['code' => 'D01', 'name' => 'Nyeri Akut Duplikat']);
    }

    /**
     * Rencana yang tetap aktif di bawah masalah yang sudah dicabut akan
     * muncul di layar tanpa induk yang bisa dipilih.
     */
    #[Test]
    public function menonaktifkan_masalah_ikut_menonaktifkan_rencananya(): void
    {
        $this->master->deactivateProblem($this->nyeriAkut);

        $this->assertFalse($this->nyeriAkut->refresh()->is_active);
        $this->assertCount(0, $this->master->carePlansFor($this->nyeriAkut->refresh()));
    }

    #[Test]
    public function rencana_baru_di_bawah_masalah_nonaktif_ditolak(): void
    {
        $this->master->deactivateProblem($this->nyeriAkut);

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('tidak akan pernah bisa dipilih');

        $this->master->addCarePlan($this->nyeriAkut->refresh(), ['code' => 'R03', 'plan' => 'Rencana baru.']);
    }

    /** Masalah umum tetap tersedia bagi perawat spesialisasi. */
    #[Test]
    public function daftar_masalah_spesialisasi_ikut_memuat_yang_umum(): void
    {
        $this->master->addProblem(['code' => 'A01', 'name' => 'Risiko Jatuh Anak', 'specialty' => 'anak']);

        $daftar = $this->master->problems('anak')->pluck('code')->all();

        $this->assertContains('A01', $daftar);
        $this->assertContains('D01', $daftar);
    }

    // ---------------------------------------------------------------- asuhan

    #[Test]
    public function masalah_ditegakkan_dengan_nama_yang_dibekukan(): void
    {
        $asesmen = $this->asesmen();

        $d = $this->asuhan->addDiagnosis($asesmen, 'D01', [], $this->perawat);

        $this->assertSame('Nyeri Akut', $d->problem_name);
        $this->assertSame('D.0077', $d->standard_code);
        $this->assertSame(1, $d->priority);
        $this->assertSame('Ns. Uji', $d->recorded_by_name);
    }

    /**
     * ATURAN KEDUA: master direvisi, asuhan yang sudah ditulis tidak ikut
     * berubah kalimatnya.
     */
    #[Test]
    public function revisi_master_tidak_mengubah_asuhan_yang_sudah_ditulis(): void
    {
        $asesmen = $this->asesmen();
        $d = $this->asuhan->addDiagnosis($asesmen, 'D01', [], $this->perawat);
        $p = $this->asuhan->addCarePlan($d, 'R01');

        $this->nyeriAkut->update(['name' => 'Nyeri Akut (revisi SDKI 2027)']);
        $this->nyeriAkut->carePlans()->where('code', 'R01')->update(['plan' => 'Bunyi rencana yang diubah.']);

        $this->assertSame('Nyeri Akut', $d->refresh()->problem_name);
        $this->assertStringContainsString('Identifikasi lokasi', $p->refresh()->plan);
    }

    /**
     * INTI ITEM INI: rencana tidak bisa dipilih di bawah masalah yang bukan
     * induknya — aturan yang Khanza tuliskan di master tapi tidak dijaga
     * di transaksinya.
     */
    #[Test]
    public function rencana_milik_masalah_lain_ditolak(): void
    {
        $lain = $this->master->addProblem(['code' => 'D02', 'name' => 'Ansietas']);
        $this->master->addCarePlan($lain, ['code' => 'R09', 'plan' => 'Latih teknik relaksasi.']);

        $asesmen = $this->asesmen();
        $d = $this->asuhan->addDiagnosis($asesmen, 'D01');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('selalu melekat pada masalah yang mendasarinya');

        $this->asuhan->addCarePlan($d, 'R09');
    }

    #[Test]
    public function rencana_yang_sah_tersimpan_berikut_kode_standarnya(): void
    {
        $d = $this->asuhan->addDiagnosis($this->asesmen(), 'D01');

        $p = $this->asuhan->addCarePlan($d, 'R01');

        $this->assertSame('I.08238', $p->standard_code);
        $this->assertSame(NursingCarePlanItem::DIRENCANAKAN, $p->status);
        $this->assertSame($d->id, $p->nursing_diagnosis_id);
    }

    #[Test]
    public function masalah_yang_sama_tidak_bisa_ditegakkan_dua_kali(): void
    {
        $asesmen = $this->asesmen();
        $this->asuhan->addDiagnosis($asesmen, 'D01');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('sudah ditegakkan pada asesmen ini');

        $this->asuhan->addDiagnosis($asesmen, 'D01');
    }

    #[Test]
    public function masalah_di_luar_master_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak ada di master umum');

        $this->asuhan->addDiagnosis($this->asesmen(), 'Z99');
    }

    #[Test]
    public function urutan_prioritas_bertambah_otomatis(): void
    {
        $lain = $this->master->addProblem(['code' => 'D02', 'name' => 'Ansietas']);
        $asesmen = $this->asesmen();

        $pertama = $this->asuhan->addDiagnosis($asesmen, 'D01');
        $kedua = $this->asuhan->addDiagnosis($asesmen, 'D02');

        $this->assertSame(1, $pertama->priority);
        $this->assertSame(2, $kedua->priority);
    }

    /**
     * ATURAN KETIGA: lembar yang sudah dikunci adalah pernyataan yang
     * sudah selesai.
     */
    #[Test]
    public function asesmen_final_tidak_bisa_ditambahi_asuhan(): void
    {
        $asesmen = $this->asesmen();
        $this->formulir->save($asesmen, ['keluhan' => 'Nyeri dada.']);
        $this->formulir->finalize($asesmen->refresh(), $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak bisa ditambahi asuhan keperawatan');

        $this->asuhan->addDiagnosis($asesmen->refresh(), 'D01');
    }

    // ----------------------------------------------------- status pelaksanaan

    #[Test]
    public function status_rencana_bisa_dinaikkan_jadi_dikerjakan(): void
    {
        $p = $this->asuhan->addCarePlan($this->asuhan->addDiagnosis($this->asesmen(), 'D01'), 'R01');

        $hasil = $this->asuhan->updatePlanStatus($p, NursingCarePlanItem::DIKERJAKAN);

        $this->assertSame(NursingCarePlanItem::DIKERJAKAN, $hasil->status);
    }

    /**
     * Rencana yang hilang tanpa keterangan tidak bisa dibedakan dari
     * rencana yang terlupakan.
     */
    #[Test]
    public function menghentikan_rencana_wajib_beralasan(): void
    {
        $p = $this->asuhan->addCarePlan($this->asuhan->addDiagnosis($this->asesmen(), 'D01'), 'R01');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('wajib disertai alasan');

        $this->asuhan->updatePlanStatus($p, NursingCarePlanItem::DIHENTIKAN);
    }

    #[Test]
    public function status_rencana_yang_asing_ditolak(): void
    {
        $p = $this->asuhan->addCarePlan($this->asuhan->addDiagnosis($this->asesmen(), 'D01'), 'R01');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->asuhan->updatePlanStatus($p, 'selesai');
    }

    // ---------------------------------------------------------------- daftar

    /**
     * Rencana yang tertinggal di bawah masalah yang dicabut adalah
     * intervensi tanpa indikasi — persis yang aturan pertama hindari.
     */
    #[Test]
    public function mencabut_masalah_ikut_mencabut_rencananya(): void
    {
        $asesmen = $this->asesmen();
        $d = $this->asuhan->addDiagnosis($asesmen, 'D01');
        $this->asuhan->addCarePlan($d, 'R01');
        $this->asuhan->addCarePlan($d, 'R02');

        $this->assertSame(2, NursingCarePlanItem::query()->count());

        $this->asuhan->removeDiagnosis($d);

        $this->assertSame(0, NursingDiagnosis::query()->count());
        $this->assertSame(0, NursingCarePlanItem::query()->count());
    }

    /** Masalah tanpa rencana sah dicatat, tapi harus terlihat. */
    #[Test]
    public function masalah_tanpa_rencana_muncul_di_daftar_tersendiri(): void
    {
        $lain = $this->master->addProblem(['code' => 'D02', 'name' => 'Ansietas']);
        $asesmen = $this->asesmen();

        $berencana = $this->asuhan->addDiagnosis($asesmen, 'D01');
        $this->asuhan->addCarePlan($berencana, 'R01');
        $this->asuhan->addDiagnosis($asesmen, 'D02');

        $tanpa = $this->asuhan->withoutCarePlan($asesmen->registration_id);

        $this->assertCount(1, $tanpa);
        $this->assertSame('D02', $tanpa->first()->problem_code);
    }

    #[Test]
    public function asuhan_satu_asesmen_terbaca_berikut_rencananya(): void
    {
        $asesmen = $this->asesmen();
        $d = $this->asuhan->addDiagnosis($asesmen, 'D01');
        $this->asuhan->addCarePlan($d, 'R01');
        $this->asuhan->addCarePlan($d, 'R02');

        $hasil = $this->asuhan->forAssessment($asesmen);

        $this->assertCount(1, $hasil);
        $this->assertCount(2, $hasil->first()->carePlanItems);
    }

    #[Test]
    public function basis_data_menolak_rencana_ganda_di_bawah_satu_masalah(): void
    {
        $d = $this->asuhan->addDiagnosis($this->asesmen(), 'D01');
        $this->asuhan->addCarePlan($d, 'R01');

        $this->expectException(QueryException::class);

        NursingCarePlanItem::query()->create([
            'nursing_diagnosis_id' => $d->id,
            'plan_code' => 'R01',
            'plan' => 'Disisipkan langsung.',
            'status' => NursingCarePlanItem::DIRENCANAKAN,
        ]);
    }

    // ------------------------------------------------------------------ bantu

    private function asesmen(): FormResponse
    {
        return $this->formulir->open($this->daftarkan()->id, 'asesmen-keperawatan-ralan', $this->perawat);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Asuhan ' . $urut, 'sex' => 'L', 'birth_date' => '1985-05-05',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
