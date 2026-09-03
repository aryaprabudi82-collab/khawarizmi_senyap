<?php

namespace Tests\Feature\Envlab;

use App\Modules\Envlab\Services\EnvlabMasterDataService;
use App\Modules\Envlab\Services\SampleTestService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecapScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $penyelia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->penyelia = User::query()->create([
            'username' => 'uji-rekap', 'name' => 'Penyelia Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->penyelia->roles()->attach(Role::query()->where('code', 'penyelia-lab-kesling')->firstOrFail());
    }

    #[Test]
    public function rekap_menampilkan_ringkasan_status_dan_pembayaran(): void
    {
        $petugas = User::query()->create([
            'username' => 'uji-rekap-petugas', 'name' => 'Petugas Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $petugas->roles()->attach(Role::query()->where('code', 'petugas-lab-kesling')->firstOrFail());

        $master = app(EnvlabMasterDataService::class);
        $pelanggan = $master->createCustomer(['code' => 'PLG-001', 'name' => 'PT Rekap', 'kind' => 'eksternal', 'is_active' => true]);
        $sampel = $master->createSampleType(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah', 'is_active' => true]);
        $parameter = $master->createParameter(['code' => 'PAR-BOD', 'name' => 'BOD', 'is_active' => true]);

        $tests = app(SampleTestService::class);
        $permintaan = $tests->request($pelanggan->id, $sampel->id, [$parameter->id], null, $petugas);
        $tests->markPaid($permintaan, 500000);

        $this->actingAs($this->penyelia)
            ->get(route('envlab-recap.index'))
            ->assertOk()
            ->assertSee('PT Rekap')
            ->assertSee('500.000');
    }

    #[Test]
    public function petugas_operasional_ditolak_mengakses_rekap(): void
    {
        $petugas = User::query()->create([
            'username' => 'uji-rekap-forbidden', 'name' => 'Petugas Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $petugas->roles()->attach(Role::query()->where('code', 'petugas-lab-kesling')->firstOrFail());

        $this->actingAs($petugas)
            ->get(route('envlab-recap.index'))
            ->assertForbidden();
    }
}
