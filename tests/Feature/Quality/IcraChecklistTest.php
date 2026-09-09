<?php

namespace Tests\Feature\Quality;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Quality\Models\IcraActivityType;
use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraAssessment;
use App\Modules\Quality\Models\IcraClassRequirement;
use App\Modules\Quality\Models\IcraPrecautionClass;
use App\Modules\Quality\Models\IcraRiskGroup;
use App\Modules\Quality\Models\IcraRiskItem;
use App\Modules\Quality\Services\IcraService;
use App\Modules\Quality\Services\QualityException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Identifikasi risiko & pemenuhan persyaratan ICRA (domain R item B).
 *
 * Yang dikunci:
 *
 * 1. EMPAT KODE IDENTIFIKASI RISIKO ADALAH DAFTAR PERIKSA, bukan
 *    tingkatan — kolom tingkatan mencatat kesimpulan tanpa dasarnya.
 * 2. BELUM DIPERIKSA ADALAH NULL, bukan false.
 * 3. PERSYARATAN DISALIN dari kelas, tidak dirujuk.
 * 4. PENUTUPAN DITAHAN oleh persyaratan yang belum DIJAWAB, tapi tidak
 *    oleh persyaratan yang dijawab TIDAK DIPENUHI.
 */
class IcraChecklistTest extends TestCase
{
    use RefreshDatabase;

    private IcraService $icra;

    private User $adminMutu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->icra = app(IcraService::class);

        $this->adminMutu = User::query()->create([
            'username' => 'uji-mutu-checklist', 'name' => 'Admin Mutu Checklist',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMutu->roles()->attach(Role::query()->where('code', 'admin-mutu')->firstOrFail());
    }

    // ============================================ daftar periksa

    #[Test]
    public function daftar_periksa_lahir_dengan_seluruh_butir_belum_diperiksa(): void
    {
        $this->butirRisiko('infeksi', 'RI-01', 'Ada pasien imunokompromais di area berdekatan');
        $this->butirRisiko('kebakaran', 'RK-01', 'Pekerjaan panas (las/gerinda) di area kerja');

        $kajian = $this->icra->prepareChecklist($this->kaji('B', '2'));

        /*
         * Daftar periksa berkotak-centang biasa cuma punya dua keadaan, dan
         * yang tidak tercentang lalu terbaca "risiko ini tidak ada" —
         * padahal bisa saja tidak ada yang melihatnya. ICRA yang berbunyi
         * "tidak ada risiko kebakaran" tanpa ada yang memeriksanya adalah
         * dokumen yang akan dikutip setelah kebakaran terjadi.
         */
        $this->assertCount(2, $kajian->risks);

        foreach ($kajian->risks as $butir) {
            $this->assertNull($butir->present);
            $this->assertTrue($butir->belumDiperiksa());
        }
    }

    #[Test]
    public function butir_yang_diperiksa_dan_tidak_ada_berbeda_dari_yang_belum_diperiksa(): void
    {
        $this->butirRisiko('kebakaran', 'RK-02', 'Pekerjaan panas di area kerja');
        $kajian = $this->icra->prepareChecklist($this->kaji('B', '2'));

        $butir = $kajian->risks->first();
        $this->icra->markRisk($butir, false, 'Tidak ada pekerjaan panas pada lingkup ini');

        $butir->refresh();

        $this->assertFalse($butir->present);
        $this->assertFalse($butir->belumDiperiksa());
        $this->assertNotEmpty($butir->note);
    }

    #[Test]
    public function butir_bisa_dikembalikan_ke_keadaan_belum_diperiksa(): void
    {
        $this->butirRisiko('utilitas', 'RU-01', 'Pemadaman listrik terencana');
        $kajian = $this->icra->prepareChecklist($this->kaji('A', '1'));

        $butir = $kajian->risks->first();
        $this->icra->markRisk($butir, true, 'Ada');

        // Dipakai saat penilai salah menandai — bukan cara menghapus jejak,
        // karena keadaan "belum diperiksa" tetap terbaca sebagai belum
        // diperiksa, bukan sebagai "tidak ada".
        $this->icra->markRisk($butir->refresh(), null);

        $this->assertNull($butir->refresh()->present);
    }

    #[Test]
    public function kategori_yang_daftar_periksanya_belum_disentuh_terdaftar_apa_adanya(): void
    {
        $this->butirRisiko('infeksi', 'RI-02', 'Debu ke arah ruang rawat');
        $this->butirRisiko('kebakaran', 'RK-03', 'Pekerjaan panas');

        $kajian = $this->icra->prepareChecklist($this->kaji('B', '3'));

        $this->icra->markRisk($kajian->risks->firstWhere('category', 'infeksi'), true, 'Ada');

        /*
         * Tidak memblokir apa pun — ini daftar kejujuran, sejenis dengan
         * pendingData() pada domain O: kesimpulan tanpa dasar yang BISA
         * DILIHAT lebih berguna daripada kesimpulan yang tampak beres.
         */
        $this->assertSame(['kebakaran'], $kajian->load('risks')->kategoriTanpaBukti());
    }

    #[Test]
    public function butir_risiko_disalin_bukan_dirujuk(): void
    {
        $butir = $this->butirRisiko('infeksi', 'RI-03', 'Kalimat asli');
        $kajian = $this->icra->prepareChecklist($this->kaji('B', '2'));

        // Master diubah SETELAH kajian dibuat.
        $butir->update(['name' => 'Kalimat yang sudah direvisi']);

        // Bunyi kajian yang sudah ditandatangani tidak boleh berubah
        // sendiri tanpa ada yang menyentuhnya.
        $this->assertSame('Kalimat asli', $kajian->load('risks')->risks->first()->label);
    }

    #[Test]
    public function daftar_butir_risiko_sengaja_lahir_kosong(): void
    {
        // Butirnya disusun IPCN RSP UI; mengarangnya berarti menerbitkan
        // daftar periksa resmi yang tidak pernah ditinjau siapa pun — lalu
        // proyek dinilai lengkap karena seluruh butir karangan tercentang.
        $this->assertSame(0, DB::table('quality.icra_risk_items')->count());
    }

    // ============================================== persyaratan

    #[Test]
    public function persyaratan_disalin_dari_kelas_yang_ditetapkan_matriks(): void
    {
        $kelasIV = IcraPrecautionClass::query()->where('code', 'IV')->firstOrFail();
        $this->persyaratan($kelasIV, 'Pasang barrier kedap penuh sampai plafon');
        $this->persyaratan($kelasIV, 'Pasang anteroom dengan pemantauan tekanan');

        // Tipe D di Kelompok 4 -> Kelas IV.
        $kajian = $this->icra->prepareChecklist($this->kaji('D', '4'));

        $this->assertCount(2, $kajian->requirements);
        $this->assertSame([1, 2], $kajian->requirements->pluck('position')->all());

        foreach ($kajian->requirements as $s) {
            $this->assertNull($s->fulfilled);
        }
    }

    #[Test]
    public function persyaratan_yang_dibekukan_tidak_berubah_saat_spo_direvisi(): void
    {
        $kelasIV = IcraPrecautionClass::query()->where('code', 'IV')->firstOrFail();
        $syarat = $this->persyaratan($kelasIV, 'Kalimat SPO tahun ini');

        $kajian = $this->icra->prepareChecklist($this->kaji('D', '4'));

        $syarat->update(['requirement' => 'Kalimat SPO tahun depan']);

        /*
         * Revisi SPO tahun depan tidak boleh mengubah daftar persyaratan
         * proyek tahun ini — termasuk proyek yang sudah selesai dan sudah
         * dinyatakan memenuhi seluruhnya.
         */
        $this->assertSame('Kalimat SPO tahun ini', $kajian->load('requirements')->requirements->first()->requirement);
    }

    #[Test]
    public function penyimpangan_persyaratan_wajib_berketerangan(): void
    {
        $kelasIV = IcraPrecautionClass::query()->where('code', 'IV')->firstOrFail();
        $this->persyaratan($kelasIV, 'Pasang anteroom');

        $kajian = $this->icra->prepareChecklist($this->kaji('D', '4'));

        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/tanpa memberi tahu apanya/');

        $this->icra->markRequirement($kajian->requirements->first(), false, null);
    }

    #[Test]
    public function basis_data_menolak_penyimpangan_tanpa_keterangan(): void
    {
        $kelasIV = IcraPrecautionClass::query()->where('code', 'IV')->firstOrFail();
        $this->persyaratan($kelasIV, 'Pasang anteroom');
        $kajian = $this->icra->prepareChecklist($this->kaji('D', '4'));

        $this->expectException(QueryException::class);

        DB::table('quality.icra_assessment_requirements')
            ->where('id', $kajian->requirements->first()->id)
            ->update(['fulfilled' => false, 'note' => null]);
    }

    // ========================================= penutupan kajian

    #[Test]
    public function kajian_tidak_bisa_ditutup_selama_ada_persyaratan_belum_dijawab(): void
    {
        $kelasIV = IcraPrecautionClass::query()->where('code', 'IV')->firstOrFail();
        $this->persyaratan($kelasIV, 'Pasang barrier kedap penuh');
        $this->persyaratan($kelasIV, 'Pasang anteroom');

        $kajian = $this->icra->prepareChecklist($this->kaji('D', '4'));

        $this->icra->markRequirement($kajian->requirements->first(), true, null, 'dr. IPCN');

        /*
         * Menutup pengkajian dengan persyaratan yang belum dijawab berarti
         * tidak ada yang memeriksa apakah barrier benar-benar terpasang,
         * dan dokumennya akan terbaca seolah seluruhnya beres.
         */
        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/persyaratan belum dijawab/');

        $this->icra->complete($kajian->load('requirements'));
    }

    #[Test]
    public function kajian_boleh_ditutup_dengan_persyaratan_yang_tidak_dipenuhi(): void
    {
        $kelasIV = IcraPrecautionClass::query()->where('code', 'IV')->firstOrFail();
        $this->persyaratan($kelasIV, 'Pasang anteroom');

        $kajian = $this->icra->prepareChecklist($this->kaji('D', '4'));

        $this->icra->markRequirement(
            $kajian->requirements->first(),
            false,
            'Anteroom tidak memungkinkan; dikompensasi dengan penjadwalan pekerjaan di luar jam layanan',
            'dr. IPCN'
        );

        /*
         * Penyimpangan yang tercatat berikut alasannya adalah catatan jujur
         * yang justru berguna. Menahannya akan mendorong petugas mengubahnya
         * jadi "dipenuhi" supaya proyeknya bisa ditutup — dan jejak
         * satu-satunya bahwa ada yang tidak terpasang akan hilang.
         */
        $hasil = $this->icra->complete($kajian->load('requirements'));

        $this->assertSame(IcraAssessment::STATUS_SELESAI, $hasil->status);
    }

    #[Test]
    public function kajian_tanpa_persyaratan_tetap_bisa_ditutup(): void
    {
        // Kelas yang SPO-nya belum ditetapkan RSP UI tidak boleh membuat
        // proyek mustahil ditutup — daftar kosong bukan daftar yang belum
        // dijawab.
        $kajian = $this->icra->prepareChecklist($this->kaji('A', '1'));

        $this->assertSame(IcraAssessment::STATUS_SELESAI, $this->icra->complete($kajian)->status);
    }

    #[Test]
    public function butir_risiko_tidak_bisa_diubah_setelah_kajian_ditutup(): void
    {
        $this->butirRisiko('infeksi', 'RI-04', 'Debu ke ruang rawat');
        $kajian = $this->icra->prepareChecklist($this->kaji('A', '1'));

        $this->icra->complete($kajian);

        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/sudah selesai atau dibatalkan/');

        $this->icra->markRisk($kajian->risks->first(), true, 'Terlambat');
    }

    // -------------------------------------------------------- fixture

    private function butirRisiko(string $kategori, string $kode, string $nama): IcraRiskItem
    {
        return IcraRiskItem::query()->create([
            'category' => $kategori, 'code' => $kode, 'name' => $nama,
            'position' => 1, 'is_active' => true,
        ]);
    }

    private function persyaratan(IcraPrecautionClass $kelas, string $kalimat): IcraClassRequirement
    {
        $urut = (int) IcraClassRequirement::query()->where('precaution_class_id', $kelas->id)->max('position');

        return IcraClassRequirement::query()->create([
            'precaution_class_id' => $kelas->id,
            'position' => $urut + 1,
            'requirement' => $kalimat,
            'is_active' => true,
        ]);
    }

    private function kaji(string $tipe, string $kelompok): IcraAssessment
    {
        static $urut = 0;
        $urut++;

        $area = IcraArea::query()->create([
            'code' => 'AREA-CL-'.$urut,
            'name' => 'Area Uji '.$urut,
            'risk_group_id' => IcraRiskGroup::query()->where('code', $kelompok)->value('id'),
            'is_active' => true,
        ]);

        return $this->icra->assess([
            'project_name' => 'Proyek Uji '.$urut,
            'project_type' => 'renovasi',
            'location' => $area->name,
            'infection_risk_level' => 'sedang',
            'fire_risk_level' => 'rendah',
            'safety_risk_level' => 'sedang',
            'utility_risk_level' => 'rendah',
        ], $this->adminMutu->id,
            IcraActivityType::query()->where('code', $tipe)->firstOrFail(),
            $area
        );
    }
}
