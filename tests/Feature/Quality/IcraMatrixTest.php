<?php

namespace Tests\Feature\Quality;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Quality\Models\IcraActivityType;
use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraMatrixCell;
use App\Modules\Quality\Models\IcraPrecautionClass;
use App\Modules\Quality\Models\IcraRiskGroup;
use App\Modules\Quality\Services\IcraService;
use App\Modules\Quality\Services\QualityException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kosakata & matriks ICRA (domain R item A).
 *
 * Yang dikunci:
 *
 * 1. KELAS PENCEGAHAN DIHITUNG DARI MATRIKS, tidak pernah diterima dari
 *    pemanggil — kelas ICRA bukan pendapat.
 * 2. SEL YANG MEMBERI RENTANG MENUNTUT KEPUTUSAN KOMITE, berikut siapa
 *    yang memutuskan dan alasannya.
 * 3. MATRIKSNYA DATA, bukan kode program — bisa dikoreksi IPCN tanpa
 *    migrasi.
 * 4. AREA LAHIR KOSONG, kelompok risikonya tidak.
 */
class IcraMatrixTest extends TestCase
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
            'username' => 'uji-mutu-icra', 'name' => 'Admin Mutu ICRA',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMutu->roles()->attach(Role::query()->where('code', 'admin-mutu')->firstOrFail());
    }

    // ============================================ kelas dari matriks

    #[Test]
    public function kelas_pencegahan_dihitung_dari_matriks_bukan_diketik(): void
    {
        // Tipe D (pembongkaran besar) di area Kelompok 4 (kamar operasi).
        $kajian = $this->kaji('D', '4', [
            // Pemanggil mengusulkan kelas yang TERLALU RENDAH.
            'risk_class' => 'I',
            'precaution_class_id' => IcraPrecautionClass::query()->where('code', 'I')->value('id'),
        ]);

        /*
         * Dengan kelas yang diketik, proyek Tipe D di ruang isolasi bisa
         * tercatat Kelas I dan tidak ada yang menolaknya. Akibatnya bukan
         * salah pencatatan: kelas menentukan pengendalian yang WAJIB
         * dipasang, jadi kelas terlalu rendah berarti konstruksi berjalan
         * tanpa barrier di sebelah pasien yang paling rentan.
         */
        $this->assertSame('IV', $kajian->risk_class);
        $this->assertSame(
            IcraPrecautionClass::query()->where('code', 'IV')->value('id'),
            $kajian->precaution_class_id
        );
    }

    #[Test]
    public function aktivitas_ringan_di_area_berisiko_rendah_menghasilkan_kelas_satu(): void
    {
        $kajian = $this->kaji('A', '1');

        $this->assertSame('I', $kajian->risk_class);
        $this->assertNull($kajian->class_decided_by);
    }

    #[Test]
    public function aktivitas_ringan_di_area_paling_berisiko_tetap_naik_kelas(): void
    {
        // Tipe A di Kelompok 4 bukan Kelas I — pekerjaan seringan apa pun
        // di kamar operasi tetap menuntut pengendalian debu aktif.
        $kajian = $this->kaji('A', '4');

        $this->assertSame('II', $kajian->risk_class);
    }

    #[Test]
    public function kelompok_risiko_diambil_dari_area_bukan_dari_pemanggil(): void
    {
        $lain = IcraRiskGroup::query()->where('code', '1')->firstOrFail();

        $kajian = $this->kaji('C', '4', [
            // Pemanggil mengaku areanya berisiko rendah.
            'risk_group_id' => $lain->id,
        ], 'III', 'dr. IPCN', 'Barrier penuh dinilai memadai tanpa anteroom');

        // Kelompok risiko melekat pada AREA, dan area itulah yang dipilih —
        // menerima kelompok dari pemanggil membuat siapa pun bisa
        // menurunkan kelas hanya dengan mengaku areanya lain.
        $kelompokArea = IcraRiskGroup::query()->where('code', '4')->value('id');
        $this->assertSame($kelompokArea, $kajian->risk_group_id);
    }

    // ================================== sel yang menuntut keputusan

    #[Test]
    public function sel_bernilai_rentang_menolak_disimpan_tanpa_keputusan_komite(): void
    {
        /*
         * Sel "III/IV" bukan sel yang belum diputuskan penyusun tabel:
         * standarnya memang menyerahkan pilihan kepada komite pengendalian
         * infeksi. Memaksanya jadi satu kelas menyembunyikan keputusan yang
         * standarnya justru mensyaratkan ada.
         */
        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/komite pengendalian infeksi/');

        $this->kaji('B', '4');
    }

    #[Test]
    public function pemilihan_kelas_dari_rentang_wajib_menyebut_pemutus_dan_alasan(): void
    {
        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/tidak berpenanggung jawab/');

        $this->kaji('B', '4', [], 'IV', 'dr. IPCN', null);
    }

    #[Test]
    public function kelas_di_luar_rentang_matriks_ditolak(): void
    {
        // Sel B/4 memberi rentang III-IV; Kelas I di luar rentang itu.
        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/di luar rentang/');

        $this->kaji('B', '4', [], 'I', 'dr. IPCN', 'Dinilai cukup');
    }

    #[Test]
    public function kelas_yang_dipilih_dari_rentang_tersimpan_berikut_pemutusnya(): void
    {
        $kajian = $this->kaji('C', '3', [], 'IV', 'dr. Rina (IPCN)', 'Berdekatan dengan ruang rawat imunokompromais');

        $this->assertSame('IV', $kajian->risk_class);
        $this->assertSame('dr. Rina (IPCN)', $kajian->class_decided_by);
        $this->assertNotEmpty($kajian->class_decision_reason);
    }

    #[Test]
    public function basis_data_menolak_pemutus_tanpa_alasan(): void
    {
        // Service bukan satu-satunya pintu ke tabel ini.
        $this->expectException(QueryException::class);

        DB::table('quality.icra_assessments')->insert([
            'assessment_number' => 'ICRA-LANGSUNG', 'project_name' => 'Lewat pintu belakang',
            'project_type' => 'renovasi', 'location' => 'Lantai 3',
            'infection_risk_level' => 'tinggi', 'fire_risk_level' => 'rendah',
            'safety_risk_level' => 'sedang', 'utility_risk_level' => 'rendah',
            'risk_class' => 'IV', 'assessed_at' => now(), 'status' => 'aktif',
            'class_decided_by' => 'dr. IPCN', 'class_decision_reason' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ================================================ matriks data

    #[Test]
    public function matriks_lengkap_enam_belas_sel(): void
    {
        // Empat tipe aktivitas x empat kelompok risiko. Sel yang hilang
        // berarti ada kombinasi yang kelasnya tidak bisa ditentukan, dan
        // menebaknya berarti menetapkan pengendalian tanpa dasar.
        $this->assertSame(16, IcraMatrixCell::query()->count());

        foreach (IcraActivityType::query()->get() as $tipe) {
            foreach (IcraRiskGroup::query()->get() as $grup) {
                $this->assertTrue(
                    IcraMatrixCell::query()
                        ->where('activity_type_id', $tipe->id)
                        ->where('risk_group_id', $grup->id)
                        ->exists(),
                    "Sel {$tipe->code}/{$grup->code} hilang."
                );
            }
        }
    }

    #[Test]
    public function matriks_bisa_dikoreksi_tanpa_migrasi(): void
    {
        /*
         * Matriksnya DATA, bukan kode program — alasannya sama dengan sumbu
         * grafik domain O dan ambang skor template domain M: pedoman
         * direvisi tanpa memberi tahu pemrogram, dan IPCN harus bisa
         * mencocokkannya dengan acuan mereka sendiri.
         */
        $sel = IcraMatrixCell::query()
            ->where('activity_type_id', IcraActivityType::query()->where('code', 'A')->value('id'))
            ->where('risk_group_id', IcraRiskGroup::query()->where('code', '1')->value('id'))
            ->firstOrFail();

        $sel->update(['min_class_id' => IcraPrecautionClass::query()->where('code', 'II')->value('id')]);

        $this->assertSame('II', $this->kaji('A', '1')->risk_class);
    }

    #[Test]
    public function kombinasi_tanpa_sel_matriks_ditolak(): void
    {
        IcraMatrixCell::query()
            ->where('activity_type_id', IcraActivityType::query()->where('code', 'A')->value('id'))
            ->where('risk_group_id', IcraRiskGroup::query()->where('code', '2')->value('id'))
            ->delete();

        $this->expectException(QualityException::class);
        $this->expectExceptionMessageMatches('/menetapkan pengendalian konstruksi tanpa dasar/');

        $this->kaji('A', '2');
    }

    // ================================================ kosakata

    #[Test]
    public function kelompok_risiko_dan_kelas_terisi_dari_pedoman(): void
    {
        // Kosakata yang ditetapkan DI LUAR rumah sakit boleh disalin —
        // garis yang sama dipakai untuk register TB dan ragam disabilitas.
        $this->assertSame(['A', 'B', 'C', 'D'], IcraActivityType::query()->orderBy('position')->pluck('code')->all());
        $this->assertSame(['1', '2', '3', '4'], IcraRiskGroup::query()->orderBy('position')->pluck('code')->all());
        $this->assertSame(['I', 'II', 'III', 'IV'], IcraPrecautionClass::query()->orderBy('position')->pluck('code')->all());
    }

    #[Test]
    public function area_tindakan_dan_persyaratan_sengaja_lahir_kosong(): void
    {
        /*
         * Ruang mana masuk kelompok risiko mana adalah keputusan RSP UI —
         * ruang endoskopi bisa masuk Tinggi di satu rumah sakit dan Sangat
         * Tinggi di rumah sakit lain, tergantung layanan apa yang ada di
         * sebelahnya. Begitu pula kalimat tindakan pengendalian dan
         * persyaratan: keduanya SPO RSP UI, dan mengarangnya berarti
         * menerbitkan perintah kerja konstruksi yang tidak pernah disahkan
         * siapa pun.
         */
        $this->assertSame(0, DB::table('quality.icra_areas')->count());
        $this->assertSame(0, DB::table('quality.icra_control_measures')->count());
        $this->assertSame(0, DB::table('quality.icra_class_requirements')->count());
    }

    // ==================================================== layar

    #[Test]
    public function layar_kajian_dan_master_icra_terbuka_untuk_admin_mutu(): void
    {
        $this->actingAs($this->adminMutu)->get(route('quality.icra.index'))->assertOk();
        $this->actingAs($this->adminMutu)->get(route('quality.icra.master.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-icra', 'name' => 'Dokter ICRA',
            'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('quality.icra.master.index'))->assertForbidden();
    }

    #[Test]
    public function layar_kajian_tidak_lagi_menawarkan_kelas_untuk_diketik(): void
    {
        $halaman = $this->actingAs($this->adminMutu)->get(route('quality.icra.index'))->getContent();

        // Kalau kolom kelas masih bisa dipilih di formulir, aturan matriks
        // di service cuma jadi saran — pengisi tinggal memilih kelas yang
        // paling murah pengendaliannya.
        $this->assertStringNotContainsString('name="risk_class"', $halaman);
        $this->assertStringContainsString('name="activity_type_id"', $halaman);
        $this->assertStringContainsString('name="area_id"', $halaman);
    }

    // -------------------------------------------------------- fixture

    private function kaji(
        string $tipe,
        string $kelompok,
        array $tambahan = [],
        ?string $kelasPilihan = null,
        ?string $pemutus = null,
        ?string $alasan = null
    ) {
        static $urut = 0;
        $urut++;

        $aktivitas = IcraActivityType::query()->where('code', $tipe)->firstOrFail();
        $grup = IcraRiskGroup::query()->where('code', $kelompok)->firstOrFail();

        $area = IcraArea::query()->create([
            'code' => 'AREA-'.$urut,
            'name' => 'Area Uji '.$urut,
            'risk_group_id' => $grup->id,
            'is_active' => true,
        ]);

        return $this->icra->assess(
            $tambahan + [
                'project_name' => 'Proyek Uji '.$urut,
                'project_type' => 'renovasi',
                'location' => $area->name,
                'infection_risk_level' => 'sedang',
                'fire_risk_level' => 'rendah',
                'safety_risk_level' => 'sedang',
                'utility_risk_level' => 'rendah',
                'risk_class' => 'I',
            ],
            $this->adminMutu->id,
            $aktivitas,
            $area,
            $kelasPilihan !== null ? IcraPrecautionClass::query()->where('code', $kelasPilihan)->firstOrFail() : null,
            $pemutus,
            $alasan
        );
    }
}
