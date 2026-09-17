<?php

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\Practitioner;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layar daftar pegawai & tenaga kesehatan.
 *
 * MENGUJI HAK AKSESNYA PALING DULU, dan itu bukan formalitas: layar ini
 * pernah dipasangi gerbang permission `dokter` — kode yang ada di katalog
 * tapi tidak diberikan ke satu peran pun — sehingga halamannya tidak bisa
 * dibuka siapa pun, termasuk admin. Gerbang yang benar adalah yang sungguh
 * dimiliki seseorang, bukan yang paling tepat namanya, dan hanya uji yang
 * memakai peran nyata bisa membedakan keduanya.
 */
class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $adminMaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->adminMaster = User::query()->create([
            'username' => 'uji-pegawai', 'name' => 'Admin Master Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMaster->roles()->attach(Role::query()->where('code', 'admin-master')->firstOrFail());

        $this->buatPegawai();
    }

    private function buatPegawai(): void
    {
        $data = [
            ['SDM-1', '1800001', 'Abdul Gofur', 'perawat', null, 'Ners', 'Sub Direktorat Keperawatan', 'Pegawai Tetap'],
            ['SDM-2', '1800002', 'Budi Radiografi', 'penunjang', 'radiologi', 'Radiografer', 'Unit Radiologi', 'Pegawai Tetap'],
            ['SDM-3', '1800003', 'Citra Steril', 'penunjang', 'cssd', 'Staf Sterilisasi (CSSD)', 'Sub Instalasi Binatu dan CSSD', 'Pegawai Tidak Tetap'],
            ['SDM-4', '1800004', 'Dewi Spesialis', 'dokter', null, 'Dokter Spesialis Anak', 'KSM Ilmu Kesehatan Anak', 'Spesialis Mitra'],
            ['SDM-5', '1800005', 'Eko Teknisi', 'non-medis', null, 'Teknisi ME', 'Sub Direktorat Sarana', 'Pegawai Tetap'],
        ];

        foreach ($data as [$code, $nip, $nama, $kategori, $penunjang, $jabatan, $unit, $status]) {
            Practitioner::query()->create([
                'code' => $code,
                'employee_number' => $nip,
                'name' => $nama,
                'category' => $kategori,
                'staff_kind' => 'pegawai',
                'support_type' => $penunjang,
                'position' => $jabatan,
                'unit_name' => $unit,
                'employment_status' => $status,
                'is_active' => true,
            ]);
        }
    }

    // ------------------------------------------------------------ hak akses

    #[Test]
    public function admin_master_bisa_membuka_layar(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index'))
            ->assertOk()
            ->assertSee('Data Pegawai');
    }

    #[Test]
    public function tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get(route('pegawai.index'))->assertRedirect();
    }

    /**
     * Petugas tanpa hak data master tidak boleh melihat NIP dan status
     * kepegawaian rekan kerjanya.
     */
    #[Test]
    public function petugas_tanpa_hak_master_ditolak(): void
    {
        $petugas = User::query()->create([
            'username' => 'uji-loket-pegawai', 'name' => 'Petugas Loket',
            'password' => 'password', 'is_active' => true,
        ]);
        $petugas->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugas)->get(route('pegawai.index'))->assertForbidden();
    }

    // -------------------------------------------------------------- tampilan

    #[Test]
    public function daftar_menampilkan_nip_jabatan_dan_unit_kerja(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index'))
            ->assertOk()
            ->assertSee('1800002')
            ->assertSee('Radiografer')
            ->assertSee('Unit Radiologi');
    }

    /**
     * Kategori ditampilkan dengan label RSUI, bukan kode internal.
     *
     * "perawat" adalah nilai kolom; yang dibaca petugas harus penamaan resmi
     * yang sama dengan daftar ketenagaan mereka sendiri.
     */
    #[Test]
    public function kategori_tampil_sebagai_label_bukan_kode(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index'))
            ->assertOk()
            ->assertSee('Penunjang Pelayanan')
            ->assertSee('Farmasi, Perawat, dan Tenaga Kesehatan Lainnya', false);
    }

    // ------------------------------------------------------------- penyaring

    #[Test]
    public function penyaring_kategori_membatasi_hasil(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['kategori' => 'dokter']))
            ->assertOk()
            ->assertSee('Dewi Spesialis')
            ->assertDontSee('Abdul Gofur');
    }

    #[Test]
    public function penyaring_unit_kerja_membatasi_hasil(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['unit' => 'Unit Radiologi']))
            ->assertOk()
            ->assertSee('Budi Radiografi')
            ->assertDontSee('Eko Teknisi');
    }

    #[Test]
    public function penyaring_status_kepegawaian_membatasi_hasil(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['status' => 'Pegawai Tidak Tetap']))
            ->assertOk()
            ->assertSee('Citra Steril')
            ->assertDontSee('Abdul Gofur');
    }

    /**
     * Nilai penyaring kategori yang tidak dikenal diabaikan, bukan
     * menghasilkan daftar kosong yang membingungkan.
     */
    #[Test]
    public function kategori_tak_dikenal_diabaikan(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['kategori' => 'bukan-kategori']))
            ->assertOk()
            ->assertSee('Abdul Gofur');
    }

    // -------------------------------------------------------------- pencarian

    /**
     * Pencarian menemukan lewat JABATAN, bukan hanya nama.
     *
     * Pertanyaan yang sungguh ditanyakan adalah "siapa saja radiografer kita",
     * dan orang yang bertanya justru belum tahu namanya.
     */
    #[Test]
    public function pencarian_menemukan_lewat_jabatan(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['cari' => 'radiografer']))
            ->assertOk()
            ->assertSee('Budi Radiografi')
            ->assertDontSee('Abdul Gofur');
    }

    #[Test]
    public function pencarian_menemukan_lewat_unit_kerja(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['cari' => 'CSSD']))
            ->assertOk()
            ->assertSee('Citra Steril');
    }

    /**
     * NIP dicocokkan PERSIS, bukan sebagian.
     *
     * Petugas yang mengetik NIP sudah tahu nomor yang dicari; pencocokan
     * sebagian akan memunculkan orang lain yang nomornya kebetulan memuat
     * potongan itu.
     */
    #[Test]
    public function nip_dicocokkan_persis(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['cari' => '1800002']))
            ->assertOk()
            ->assertSee('Budi Radiografi')
            ->assertDontSee('Abdul Gofur');
    }

    #[Test]
    public function pencarian_tanpa_hasil_menjelaskan_sebabnya(): void
    {
        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index', ['cari' => 'tidak-ada-orang-ini']))
            ->assertOk()
            ->assertSee('Tidak ada pegawai yang cocok');
    }

    // -------------------------------------------------------------- ringkasan

    #[Test]
    public function ringkasan_menghitung_per_kategori(): void
    {
        $html = $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index'))
            ->assertOk()
            ->getContent();

        // 2 penunjang (radiologi + cssd) dari 5 pegawai contoh.
        $this->assertStringContainsString('Penunjang Pelayanan', $html);
        $this->assertMatchesRegularExpression('/>2<\/div>\s*<div class="text-secondary small">Penunjang Pelayanan/u', $html);
    }

    // ------------------------------------------------------------------ edit

    /**
     * Super admin bisa menyunting tanpa permission eksplisit.
     *
     * Peran super-admin punya NOL permission di basis data — ia lolos lewat
     * Gate::before. Uji ini mengunci perilaku itu: kalau suatu saat jalan
     * pintas tersebut dicabut, layar ini akan tertutup bagi orang yang justru
     * paling berhak membukanya, dan tidak ada yang menyadarinya sampai ada
     * yang mencoba.
     */
    #[Test]
    public function super_admin_bisa_menyunting_pegawai(): void
    {
        $super = User::query()->create([
            'username' => 'uji-super', 'name' => 'Super Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $super->roles()->attach(Role::query()->where('code', 'super-admin')->firstOrFail());

        $p = Practitioner::query()->where('employee_number', '1800002')->firstOrFail();

        $this->actingAs($super)
            ->post(route('pegawai.perbarui', $p), [
                'name' => 'Budi Radiografi Direvisi',
                'category' => 'penunjang',
                'support_type' => 'radiologi',
                'position' => 'Radiografer Ahli',
                'unit_name' => 'Unit Radiologi',
                'employment_status' => 'Pegawai Tetap',
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame('Budi Radiografi Direvisi', $p->fresh()->name);
        $this->assertSame('Radiografer Ahli', $p->fresh()->position);
    }

    #[Test]
    public function admin_master_juga_bisa_menyunting(): void
    {
        $p = Practitioner::query()->where('employee_number', '1800001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('pegawai.perbarui', $p), [
                'name' => 'Abdul Gofur', 'category' => 'perawat',
                'position' => 'Ners Ahli', 'employment_status' => 'Pegawai Tetap',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame('Ners Ahli', $p->fresh()->position);
    }

    #[Test]
    public function petugas_tanpa_hak_master_tidak_bisa_menyunting(): void
    {
        $petugas = User::query()->create([
            'username' => 'uji-loket-edit', 'name' => 'Petugas Loket',
            'password' => 'password', 'is_active' => true,
        ]);
        $petugas->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $p = Practitioner::query()->where('employee_number', '1800001')->firstOrFail();

        $this->actingAs($petugas)
            ->post(route('pegawai.perbarui', $p), ['name' => 'Diubah Diam-diam', 'category' => 'perawat'])
            ->assertForbidden();

        $this->assertSame('Abdul Gofur', $p->fresh()->name);
    }

    /**
     * NIP tidak ikut berubah meski dikirimkan.
     *
     * Ia pengenal yang menautkan baris ini ke daftar kepegawaian; mengubahnya
     * memutus tautan itu, dan muat ulang berikutnya akan membuat baris kedua
     * untuk orang yang sama.
     */
    #[Test]
    public function nip_tidak_bisa_diubah_lewat_form(): void
    {
        $p = Practitioner::query()->where('employee_number', '1800001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('pegawai.perbarui', $p), [
                'name' => 'Abdul Gofur', 'category' => 'perawat',
                'employee_number' => '9999999', 'code' => 'DIUBAH',
            ])
            ->assertRedirect();

        $segar = $p->fresh();
        $this->assertSame('1800001', $segar->employee_number);
        $this->assertSame('SDM-1', $segar->code);
    }

    /**
     * Jenis penunjang dikosongkan bila kategorinya bukan penunjang.
     *
     * Dibiarkan terisi, ia muncul sebagai keterangan menyesatkan di bawah
     * label kategori — "Medis / radiologi".
     */
    #[Test]
    public function jenis_penunjang_dikosongkan_saat_kategori_bukan_penunjang(): void
    {
        $p = Practitioner::query()->where('employee_number', '1800002')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('pegawai.perbarui', $p), [
                'name' => 'Budi Radiografi', 'category' => 'dokter',
                'support_type' => 'radiologi',
            ])
            ->assertRedirect();

        $this->assertNull($p->fresh()->support_type);
    }

    #[Test]
    public function kategori_tak_sah_ditolak(): void
    {
        $p = Practitioner::query()->where('employee_number', '1800001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('pegawai.perbarui', $p), ['name' => 'Abdul Gofur', 'category' => 'bukan-kategori'])
            ->assertSessionHasErrors('category');
    }

    #[Test]
    public function nama_wajib_diisi(): void
    {
        $p = Practitioner::query()->where('employee_number', '1800001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('pegawai.perbarui', $p), ['name' => '', 'category' => 'perawat'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function status_aktif_bisa_dimatikan(): void
    {
        $p = Practitioner::query()->where('employee_number', '1800001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('pegawai.perbarui', $p), [
                'name' => 'Abdul Gofur', 'category' => 'perawat', 'is_active' => 0,
            ])
            ->assertRedirect();

        $this->assertFalse($p->fresh()->is_active);
    }

    /**
     * Daftar dibatasi per halaman, tidak dimuat sekaligus.
     *
     * Pada data nyata isinya 1.524 baris; memuat seluruhnya membuat halaman
     * berat dan tidak bisa dibaca.
     */
    #[Test]
    public function daftar_berpaginasi(): void
    {
        Practitioner::query()->delete();

        for ($i = 1; $i <= 30; $i++) {
            Practitioner::query()->create([
                'code' => 'SDM-P'.$i,
                'employee_number' => '99'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => 'Pegawai Nomor '.$i,
                'category' => 'perawat',
                'staff_kind' => 'pegawai',
                'position' => 'Ners',
                'unit_name' => 'Sub Direktorat Keperawatan',
                'employment_status' => 'Pegawai Tetap',
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->adminMaster)
            ->get(route('pegawai.index'))
            ->assertOk()
            ->assertSee('30 pegawai')
            ->assertSee('page=2');
    }
}
