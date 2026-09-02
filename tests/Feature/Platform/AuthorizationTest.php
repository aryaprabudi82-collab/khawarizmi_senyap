<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);
        app(PermissionRegistry::class)->flush();
    }

    #[Test]
    public function katalog_memuat_seluruh_kapabilitas_dengan_kode_yang_unik(): void
    {
        $jumlah = Permission::query()->count();

        $this->assertSame(1183, $jumlah, 'Katalog permission tidak utuh.');
        $this->assertSame($jumlah, Permission::query()->distinct()->count('code'));
    }

    #[Test]
    public function setiap_permission_terpetakan_ke_konteks_dan_domain(): void
    {
        $this->assertSame(0, Permission::query()->whereNull('context')->count());
        $this->assertSame(0, Permission::query()->whereNull('domain_code')->count());
    }

    #[Test]
    public function memuat_ulang_katalog_tidak_menggandakan_baris(): void
    {
        $sebelum = Permission::query()->count();

        $this->seed(PermissionCatalogSeeder::class);

        $this->assertSame($sebelum, Permission::query()->count());
    }

    #[Test]
    public function pengguna_mewarisi_permission_dari_perannya(): void
    {
        $user = $this->buatPengguna('petugas-daftar');

        $kodeMilikPeran = Role::query()
            ->where('code', 'petugas-daftar')
            ->firstOrFail()
            ->permissions()
            ->pluck('code')
            ->first();

        $this->assertTrue($user->hasPermission($kodeMilikPeran));
        $this->assertTrue($user->can($kodeMilikPeran));
    }

    #[Test]
    public function pengguna_ditolak_untuk_permission_di_luar_perannya(): void
    {
        $user = $this->buatPengguna('petugas-daftar');

        // Kapabilitas milik konteks farmasi, jelas di luar jangkauan pendaftaran.
        $kodeAsing = Permission::query()
            ->where('context', 'pharmacy')
            ->value('code');

        $this->assertNotNull($kodeAsing);
        $this->assertFalse($user->hasPermission($kodeAsing));
        $this->assertFalse($user->can($kodeAsing));
    }

    #[Test]
    public function super_admin_lolos_seluruh_pemeriksaan_tanpa_baris_role_permission(): void
    {
        $user = $this->buatPengguna(User::SUPER_ADMIN);

        $role = Role::query()->where('code', User::SUPER_ADMIN)->firstOrFail();

        $this->assertSame(0, $role->permissions()->count(), 'super-admin tidak boleh diberi baris permission.');
        $this->assertTrue($user->can(Permission::query()->value('code')));
        $this->assertTrue($user->can(Permission::query()->where('context', 'pharmacy')->value('code')));
    }

    #[Test]
    public function pengguna_nonaktif_kehilangan_seluruh_akses(): void
    {
        $user = $this->buatPengguna('petugas-daftar');
        $kode = $user->roles->first()->permissions()->value('code');

        $this->assertTrue($user->can($kode));

        $user->update(['is_active' => false]);

        $this->assertFalse($user->fresh()->can($kode));
    }

    #[Test]
    public function ability_yang_bukan_permission_tidak_ikut_diloloskan(): void
    {
        $user = $this->buatPengguna('petugas-daftar');

        $this->assertFalse($user->can('kapabilitas-yang-tidak-pernah-ada'));
    }

    private function buatPengguna(string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => 'uji.' . $kodePeran,
            'name' => 'Pengguna Uji ' . $kodePeran,
            'password' => 'rahasia-uji',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $kodePeran)->firstOrFail());

        return $user->fresh(['roles']);
    }
}
