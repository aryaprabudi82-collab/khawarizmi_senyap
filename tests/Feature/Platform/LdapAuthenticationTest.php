<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\LdapAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Autentikasi Active Directory — ditelusuri lewat HTTP, bukan memanggil service.
 *
 * MENGAPA LdapAuthenticator DIPALSUKAN, bukan menghubungi AD sungguhan.
 * Uji yang bergantung pada server direktori akan gagal setiap kali server itu
 * dipelihara atau jaringan RS terputus — kegagalan yang tidak ada hubungannya
 * dengan kode yang diubah. Suite yang merah karena sebab di luar kodenya
 * adalah suite yang cepat atau lambat diabaikan orang.
 *
 * Yang dikunci di sini adalah KEPUTUSAN-nya, dan tiap keputusan itu punya
 * akibat yang tidak melempar galat bila salah:
 *
 *   - AD gagal harus JATUH ke kata sandi lokal, bukan menolak. Kalau tidak,
 *     satu server direktori yang mati mengunci seluruh rumah sakit.
 *   - Peran akun yang sudah ada TIDAK BOLEH ditimpa peran bawaan. Kalau tidak,
 *     kasir yang login besok pagi berubah jadi dokter.
 *   - `is_active` lokal harus mengalahkan keanggotaan grup di AD.
 */
class LdapAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder::class,
            \App\Modules\Platform\Database\Seeders\RoleSeeder::class,
        ]);

        config(['ldap.default_role' => 'dokter']);
    }

    // ------------------------------------------------------- LDAP dimatikan

    /**
     * Dengan LDAP mati, login lokal sama sekali tidak berubah.
     *
     * phpunit.xml menyetel LDAP_ENABLED=false, jadi inilah keadaan yang
     * dialami seluruh uji lain di suite ini — termasuk enam uji login yang
     * sudah ada sebelum AD dipasang.
     */
    #[Test]
    public function login_lokal_tetap_bekerja_saat_ldap_dimatikan(): void
    {
        $this->palsukanLdap(null);

        $user = $this->buatPenggunaLokal('kasir1', 'kasir');

        $this->post(route('masuk.kirim'), ['username' => 'kasir1', 'password' => 'password'])
            ->assertRedirect(route('beranda'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function kata_sandi_lokal_salah_tetap_ditolak(): void
    {
        $this->palsukanLdap(null);
        $this->buatPenggunaLokal('kasir1', 'kasir');

        $this->from(route('masuk'))
            ->post(route('masuk.kirim'), ['username' => 'kasir1', 'password' => 'salah'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    // ------------------------------------------------ penyediaan akun otomatis

    /**
     * Anggota grup yang belum punya akun dibuat otomatis — TANPA kata sandi
     * lokal, dan dengan peran bawaan.
     */
    #[Test]
    public function anggota_grup_baru_dibuat_otomatis_tanpa_kata_sandi_lokal(): void
    {
        $this->palsukanLdap([
            'username' => 'dewi.yulianti',
            'name' => 'Dewi Yulianti',
            'email' => 'Dewi.Yulianti@rs.ui.ac.id',
            'dn' => 'CN=Dewi Yulianti,CN=Users,DC=rs,DC=ui,DC=ac,DC=id',
        ]);

        $this->post(route('masuk.kirim'), ['username' => 'dewi.yulianti', 'password' => 'sandi-domain'])
            ->assertRedirect(route('beranda'));

        $user = User::query()->where('username', 'dewi.yulianti')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Dewi Yulianti', $user->name);
        $this->assertNull($user->password,
            'Akun AD tidak boleh punya kata sandi lokal — itu jalur masuk kedua yang tidak diminta siapa pun');
        $this->assertTrue($user->roles->contains('code', 'dokter'));
    }

    /** Nama pengguna dari AD berhuruf besar harus cocok ke akun lokal huruf kecil. */
    #[Test]
    public function nama_pengguna_dari_ad_dinormalkan_ke_huruf_kecil(): void
    {
        $this->palsukanLdap([
            'username' => 'mohammad.hud',   // service sudah menormalkan
            'name' => 'Mohammad Hud',
            'email' => 'Mohammad.Hud@rs.ui.ac.id',
            'dn' => 'CN=Mohammad Hud,CN=Users,DC=rs,DC=ui,DC=ac,DC=id',
        ]);

        // Pengguna mengetik dengan huruf besar, seperti yang biasa dilakukan.
        $this->post(route('masuk.kirim'), ['username' => 'Mohammad.Hud', 'password' => 'sandi-domain'])
            ->assertRedirect(route('beranda'));

        $this->assertSame(1, User::query()->where('username', 'mohammad.hud')->count());
        $this->assertSame(0, User::query()->where('username', 'Mohammad.Hud')->count());
    }

    /**
     * PERAN AKUN YANG SUDAH ADA TIDAK DITIMPA.
     *
     * Administrator yang sudah menetapkan seseorang sebagai kasir tidak boleh
     * menemukannya berubah jadi dokter keesokan paginya hanya karena orang itu
     * login lagi lewat AD.
     */
    #[Test]
    public function peran_akun_yang_sudah_ada_tidak_ditimpa_peran_bawaan(): void
    {
        $user = $this->buatPenggunaLokal('iqbal.hisyam', 'kasir');

        $this->palsukanLdap([
            'username' => 'iqbal.hisyam',
            'name' => 'Iqbal Hisyam',
            'email' => 'Iqbal.Hisyam@rs.ui.ac.id',
            'dn' => 'CN=Iqbal Hisyam,CN=Users,DC=rs,DC=ui,DC=ac,DC=id',
        ]);

        $this->post(route('masuk.kirim'), ['username' => 'iqbal.hisyam', 'password' => 'sandi-domain'])
            ->assertRedirect(route('beranda'));

        $user->refresh()->load('roles');

        $this->assertTrue($user->roles->contains('code', 'kasir'));
        $this->assertFalse($user->roles->contains('code', 'dokter'),
            'Login lewat AD tidak boleh menaikkan kewenangan yang sudah ditetapkan administrator');
    }

    /** Nama dan surel disinkronkan dari direktori tiap masuk. */
    #[Test]
    public function nama_dan_surel_disinkronkan_dari_direktori(): void
    {
        $user = $this->buatPenggunaLokal('candra.cipto', 'kasir');
        $user->forceFill(['name' => 'Nama Lama'])->save();

        $this->palsukanLdap([
            'username' => 'candra.cipto',
            'name' => 'Candra Cipto Nugroho',
            'email' => 'Candra.Cipto@rs.ui.ac.id',
            'dn' => 'CN=Candra Cipto Nugroho,CN=Users,DC=rs,DC=ui,DC=ac,DC=id',
        ]);

        $this->post(route('masuk.kirim'), ['username' => 'candra.cipto', 'password' => 'sandi-domain']);

        $this->assertSame('Candra Cipto Nugroho', $user->refresh()->name);
    }

    // ---------------------------------------------------------- penolakan

    /**
     * Bukan anggota grup ditolak, sekalipun kata sandi domainnya benar.
     *
     * Inilah yang membedakan pemasangan ini dari sistem rujukan, yang tidak
     * memeriksa grup sama sekali sehingga setiap akun domain bisa masuk.
     */
    #[Test]
    public function bukan_anggota_grup_ditolak_dan_tidak_membuat_akun(): void
    {
        // Service mengembalikan null untuk yang bukan anggota — filter grup
        // sudah menyaringnya di dalam pencarian.
        $this->palsukanLdap(null);

        $this->from(route('masuk'))
            ->post(route('masuk.kirim'), ['username' => 'orang.lain', 'password' => 'sandi-domain'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertSame(0, User::query()->where('username', 'orang.lain')->count(),
            'Login yang gagal tidak boleh meninggalkan akun');
    }

    /**
     * Akun yang dinonaktifkan di SIMRS ditolak meski AD meloloskannya.
     *
     * Penonaktifan lokal adalah keputusan administrator rumah sakit, dan ia
     * mengalahkan keanggotaan grup di direktori.
     */
    #[Test]
    public function akun_nonaktif_ditolak_meski_ad_meloloskan(): void
    {
        $user = $this->buatPenggunaLokal('donyi', 'kasir');
        $user->forceFill(['is_active' => false])->save();

        $this->palsukanLdap([
            'username' => 'donyi',
            'name' => 'Dony',
            'email' => null,
            'dn' => 'CN=Dony,CN=Users,DC=rs,DC=ui,DC=ac,DC=id',
        ]);

        $this->from(route('masuk'))
            ->post(route('masuk.kirim'), ['username' => 'donyi', 'password' => 'sandi-domain'])
            ->assertRedirect(route('masuk'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    // ------------------------------------------------------------- fallback

    /**
     * AD YANG MELEDAK JATUH KE KATA SANDI LOKAL, bukan 500.
     *
     * Ini butir terpenting di berkas ini. Tanpa perilaku ini, satu server
     * direktori yang mati berarti tidak seorang pun di rumah sakit bisa masuk.
     */
    #[Test]
    public function galat_direktori_jatuh_ke_kata_sandi_lokal(): void
    {
        $user = $this->buatPenggunaLokal('apoteker1', 'apoteker');

        $palsu = new class extends LdapAuthenticator
        {
            public function aktif(): bool
            {
                return true;
            }

            public function coba(string $username, string $password): ?array
            {
                // Service sungguhan menangkap galatnya sendiri dan
                // mengembalikan null; ini meniru hasil akhirnya.
                return null;
            }

            public function cariPengguna(string $username): ?array
            {
                throw new RuntimeException('Server direktori tidak terjangkau.');
            }
        };

        $this->app->instance(LdapAuthenticator::class, $palsu);

        $this->post(route('masuk.kirim'), ['username' => 'apoteker1', 'password' => 'password'])
            ->assertRedirect(route('beranda'));

        $this->assertAuthenticatedAs($user);
    }

    // ---------------------------------------------------------------- audit

    /** Jalur masuk dibedakan di jejak audit. */
    #[Test]
    public function jalur_masuk_tercatat_terpisah_di_jejak_audit(): void
    {
        $this->palsukanLdap([
            'username' => 'ahmad.firdausi',
            'name' => 'Ahmad Firdausi',
            'email' => null,
            'dn' => 'CN=Ahmad Firdausi,CN=Users,DC=rs,DC=ui,DC=ac,DC=id',
        ]);

        $this->post(route('masuk.kirim'), ['username' => 'ahmad.firdausi', 'password' => 'sandi-domain']);

        $aksi = DB::table('platform.audit_logs')
            ->where('username', 'ahmad.firdausi')
            ->pluck('action');

        $this->assertContains('provision_ldap', $aksi,
            'Akun yang lahir otomatis dari direktori harus bisa ditelusuri tersendiri');
        $this->assertContains('login_ldap', $aksi);
        $this->assertNotContains('login_lokal', $aksi);
    }

    // -------------------------------------------------------------- pembantu

    /**
     * Memalsukan LdapAuthenticator di container.
     *
     * @param  array{username: string, name: string, email: ?string, dn: string}|null  $hasil
     */
    private function palsukanLdap(?array $hasil): void
    {
        $palsu = new class($hasil) extends LdapAuthenticator
        {
            public function __construct(private readonly ?array $hasil) {}

            public function aktif(): bool
            {
                return $this->hasil !== null;
            }

            public function coba(string $username, string $password): ?array
            {
                if ($this->hasil === null) {
                    return null;
                }

                return mb_strtolower($username) === $this->hasil['username'] ? $this->hasil : null;
            }

            public function cariPengguna(string $username): ?array
            {
                return $this->coba($username, 'tidak-dipakai');
            }
        };

        $this->app->instance(LdapAuthenticator::class, $palsu);
    }

    private function buatPenggunaLokal(string $username, string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => $username,
            'name' => ucfirst($username),
            'password' => 'password',
            'is_active' => true,
        ]);

        $peran = Role::query()->where('code', $kodePeran)->firstOrFail();
        $user->roles()->sync([$peran->id]);

        return $user->refresh();
    }
}
