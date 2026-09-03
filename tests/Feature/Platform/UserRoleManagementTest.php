<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Database\Seeders\UserSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $adminSistem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);
        app(PermissionRegistry::class)->flush();

        $this->adminSistem = User::query()->create([
            'username' => 'uji-admin-sistem', 'name' => 'Admin Sistem Uji', 'password' => 'rahasia', 'is_active' => true,
        ]);
        $this->adminSistem->roles()->attach(Role::query()->where('code', 'admin-sistem')->firstOrFail());
    }

    #[Test]
    public function admin_sistem_bisa_membuka_layar_kelola_pengguna_dan_peran(): void
    {
        $this->actingAs($this->adminSistem)->get(route('platform.pengguna.index'))->assertOk();
        $this->actingAs($this->adminSistem)->get(route('platform.peran.index'))->assertOk();
    }

    #[Test]
    public function peran_biasa_tidak_bisa_membuka_layar_kelola_pengguna(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-kelola', 'name' => 'Dokter Uji', 'password' => 'rahasia', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('platform.pengguna.index'))->assertForbidden();
    }

    #[Test]
    public function admin_sistem_bisa_membuat_pengguna_baru_dengan_peran(): void
    {
        $petugasDaftar = Role::query()->where('code', 'petugas-daftar')->firstOrFail();

        $response = $this->actingAs($this->adminSistem)->post(route('platform.pengguna.simpan'), [
            'username' => 'loket3',
            'name' => 'Pengguna Baru',
            'password' => 'password123',
            'roles' => [$petugasDaftar->id],
        ]);

        $response->assertRedirect();

        $baru = User::query()->where('username', 'loket3')->firstOrFail();
        $this->assertTrue($baru->must_change_password);
        $this->assertTrue($baru->roles->contains('code', 'petugas-daftar'));
    }

    #[Test]
    public function admin_sistem_bisa_membuat_peran_custom_dan_membatasinya_ke_satu_modul(): void
    {
        $this->actingAs($this->adminSistem)->post(route('platform.peran.simpan'), [
            'code' => 'admin-cssd-malam',
            'name' => 'Admin CSSD Malam',
            'description' => 'Hanya sirkulasi CSSD di luar jam kerja.',
        ])->assertRedirect();

        $peran = Role::query()->where('code', 'admin-cssd-malam')->firstOrFail();
        $this->assertFalse($peran->is_system);

        $this->actingAs($this->adminSistem)->post(route('platform.peran.perbarui', $peran), [
            'name' => $peran->name,
            'permissions' => ['sirkulasi_cssd'],
        ])->assertRedirect();

        $peran->refresh();
        $this->assertSame(['sirkulasi_cssd'], $peran->permissions()->pluck('code')->all());

        $petugas = User::query()->create([
            'username' => 'uji-cssd-malam', 'name' => 'Petugas Malam', 'password' => 'rahasia', 'is_active' => true,
        ]);
        $petugas->roles()->attach($peran);

        $this->assertTrue($petugas->fresh()->can('sirkulasi_cssd'));
        $this->assertFalse($petugas->fresh()->can('inventaris_inventaris'));
    }

    #[Test]
    public function peran_bawaan_sistem_tidak_bisa_diubah_atau_dihapus_lewat_layar(): void
    {
        $dokter = Role::query()->where('code', 'dokter')->firstOrFail();

        $this->actingAs($this->adminSistem)->post(route('platform.peran.perbarui', $dokter), [
            'name' => 'Dokter Diubah', 'permissions' => [],
        ])->assertForbidden();

        $this->actingAs($this->adminSistem)->delete(route('platform.peran.hapus', $dokter))
            ->assertForbidden();

        $this->assertSame('Dokter', $dokter->fresh()->name);
    }

    #[Test]
    public function peran_custom_yang_masih_dipakai_pengguna_tidak_bisa_dihapus(): void
    {
        $peran = Role::query()->create(['code' => 'peran-uji-hapus', 'name' => 'Peran Uji', 'is_system' => false]);

        $petugas = User::query()->create([
            'username' => 'uji-peran-hapus', 'name' => 'Petugas', 'password' => 'rahasia', 'is_active' => true,
        ]);
        $petugas->roles()->attach($peran);

        $this->actingAs($this->adminSistem)->delete(route('platform.peran.hapus', $peran))
            ->assertStatus(422);

        $this->assertDatabaseHas('platform.roles', ['code' => 'peran-uji-hapus']);
    }

    #[Test]
    public function admin_sistem_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        $this->actingAs($this->adminSistem)
            ->post(route('platform.pengguna.status', [$this->adminSistem, 'nonaktifkan']))
            ->assertStatus(422);

        $this->assertTrue($this->adminSistem->fresh()->is_active);
    }

    #[Test]
    public function menonaktifkan_pengguna_lain_mencegahnya_masuk(): void
    {
        $petugas = User::query()->create([
            'username' => 'uji-nonaktif', 'name' => 'Petugas', 'password' => 'password', 'is_active' => true,
        ]);
        $petugas->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($this->adminSistem)
            ->post(route('platform.pengguna.status', [$petugas, 'nonaktifkan']))
            ->assertRedirect();

        $this->assertFalse($petugas->fresh()->is_active);
    }

    #[Test]
    public function seeder_pengguna_membuat_akun_admin_sistem_yang_bukan_super_admin(): void
    {
        $this->seed(UserSeeder::class);

        $sistem1 = User::query()->where('username', 'sistem1')->firstOrFail();

        $this->assertTrue($sistem1->hasRole('admin-sistem'));
        $this->assertFalse($sistem1->isSuperAdmin());
        $this->assertTrue($sistem1->can('user'));
    }
}
