<?php

namespace Tests\Feature\Philanthropy;

use App\Modules\Philanthropy\Models\Assessment;
use App\Modules\Philanthropy\Models\AssessmentCriterion;
use App\Modules\Philanthropy\Models\Disbursement;
use App\Modules\Philanthropy\Services\AidEligibilityService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layar filantropi, termasuk yang TIDAK terjangkau penyapu layar.
 *
 * ScreenSmokeTest hanya membuka rute tanpa parameter, jadi layar asesmen
 * (`/zis/bantuan/asesmen/{asesmen}`) — yang justru paling banyak logikanya —
 * tidak pernah tersentuh di sana. Diuji di sini dalam dua keadaan yang
 * bentuk tampilannya memang berbeda: sebelum diputuskan (formulir jawaban
 * terbuka) dan sesudah (jawaban terkunci, penyaluran muncul).
 */
class PhilanthropyScreenTest extends TestCase
{
    use RefreshDatabase;

    private AidEligibilityService $bantuan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);
        $this->bantuan = app(AidEligibilityService::class);
    }

    #[Test]
    public function layar_asesmen_terbuka_sebelum_dan_sesudah_diputuskan(): void
    {
        $petugas = $this->petugas();

        $penerima = $this->bantuan->registerRecipient(['name' => 'Keluarga Uji']);
        $asesmen = $this->bantuan->openAssessment($penerima, [
            'assessed_on' => '2026-09-01',
            'surveyor_name' => 'Amil Survei',
        ]);

        // Sebelum putusan: formulir jawaban terbuka untuk keenam belas kategori.
        $this->actingAs($petugas)
            ->get(route('philanthropy.bantuan.asesmen', $asesmen))
            ->assertOk()
            ->assertSee('Enam Belas Kategori Survei')
            ->assertSee('Putusan Kelayakan');

        $this->bantuan->decide(
            $asesmen, Assessment::PUTUSAN_LAYAK,
            'Rumah tidak layak huni.', 'Ketua Panitia ZIS', 500000
        );

        $this->bantuan->disburse($asesmen->fresh(), [
            'fund_source' => Disbursement::SUMBER_INFAK,
            'amount' => 250000,
            'purpose' => 'Biaya obat',
        ]);

        // Sesudah: jawaban terkunci dan blok penyaluran muncul. Keadaan ini
        // memakai cabang blade yang lain sepenuhnya.
        $this->actingAs($petugas)
            ->get(route('philanthropy.bantuan.asesmen', $asesmen))
            ->assertOk()
            ->assertSee('Ketua Panitia ZIS')
            ->assertSee('Penyaluran atas Asesmen Ini');
    }

    #[Test]
    public function gerbang_kriteria_dan_gerbang_kerja_dipisah(): void
    {
        /*
         * Yang menyusun instrumen dan yang memutuskan siapa layak menerima
         * uang tidak harus orang yang sama. Peran yang hanya memegang salah
         * satu gerbang harus benar-benar tertahan di gerbang lainnya —
         * kalau tidak, pemisahan ini cuma hiasan pada layar peran.
         */
        $hanyaKriteria = $this->penggunaDenganKode('zis_kategori_asnaf_penerima_dankes');
        $hanyaKerja = $this->penggunaDenganKode('zis_pengeluaran_penerima_dankes');

        $this->actingAs($hanyaKriteria)->get(route('philanthropy.kriteria.index'))->assertOk();
        $this->actingAs($hanyaKriteria)->get(route('philanthropy.bantuan.index'))->assertForbidden();

        $this->actingAs($hanyaKerja)->get(route('philanthropy.bantuan.index'))->assertOk();
        $this->actingAs($hanyaKerja)->get(route('philanthropy.kriteria.index'))->assertForbidden();
    }

    #[Test]
    public function layar_kriteria_menampilkan_kategori_yang_masih_kosong(): void
    {
        $petugas = $this->petugas();

        // Kategori tanpa kosakata tidak bisa disurvei, dan itu harus terlihat
        // sebagai pekerjaan yang belum selesai — bukan tersembunyi sebagai
        // pertanyaan yang tak pernah muncul di formulir.
        $this->actingAs($petugas)
            ->get(route('philanthropy.kriteria.index'))
            ->assertOk()
            ->assertSee('15 kategori belum punya pilihan sama sekali')
            ->assertSee('Status kepemilikan rumah');
    }

    #[Test]
    public function petugas_zis_bisa_menyusun_kriteria_lewat_layar(): void
    {
        $petugas = $this->petugas();

        $this->actingAs($petugas)->post(route('philanthropy.kriteria.simpan'), [
            'category' => AssessmentCriterion::KATEGORI_LANTAI_RUMAH,
            'code' => 'LNT-TANAH',
            'name' => 'Tanah',
            'weight' => 4,
        ])->assertRedirect();

        $this->assertDatabaseHas('philanthropy.assessment_criteria', [
            'code' => 'LNT-TANAH', 'category' => 'lantai-rumah', 'weight' => 4,
        ]);
    }

    private function petugas(): User
    {
        $user = User::query()->create([
            'username' => 'uji-zis', 'name' => 'Petugas ZIS Uji',
            'password' => 'password', 'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'petugas-zis')->firstOrFail());

        return $user;
    }

    private function penggunaDenganKode(string $kode): User
    {
        $user = User::query()->create([
            'username' => 'uji-'.substr(md5($kode), 0, 8), 'name' => 'Uji '.$kode,
            'password' => 'password', 'is_active' => true,
        ]);

        $peran = Role::query()->create([
            'code' => 'uji-'.substr(md5($kode), 0, 8),
            'name' => 'Peran Uji '.$kode,
            'is_system' => false,
        ]);

        $peran->permissions()->attach(
            Permission::query()->where('code', $kode)->firstOrFail()
        );

        $user->roles()->attach($peran);

        return $user;
    }
}
