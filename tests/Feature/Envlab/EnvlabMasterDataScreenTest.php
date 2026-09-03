<?php

namespace Tests\Feature\Envlab;

use App\Modules\Envlab\Models\SampleType;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnvlabMasterDataScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasLabKesling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->petugasLabKesling = User::query()->create([
            'username' => 'uji-lab-kesling', 'name' => 'Petugas Lab Kesling Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasLabKesling->roles()->attach(Role::query()->where('code', 'petugas-lab-kesling')->firstOrFail());
    }

    #[Test]
    public function petugas_lab_kesling_dapat_membuka_layar_data_master(): void
    {
        $this->actingAs($this->petugasLabKesling)
            ->get(route('envlab-master.index'))
            ->assertOk()
            ->assertSee('Data Master Lab Kesehatan Lingkungan');
    }

    #[Test]
    public function petugas_lab_kesling_dapat_menambah_pelanggan_lewat_layar(): void
    {
        $this->actingAs($this->petugasLabKesling)
            ->post(route('envlab-master.pelanggan.simpan'), [
                'code' => 'PLG-001', 'name' => 'RSUD Mitra', 'kind' => 'eksternal',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('envlab.customers', ['code' => 'PLG-001', 'name' => 'RSUD Mitra']);
    }

    #[Test]
    public function petugas_lab_kesling_dapat_menambah_baku_mutu_lewat_layar(): void
    {
        $sampel = SampleType::query()->create(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah', 'is_active' => true]);
        $parameter = \App\Modules\Envlab\Models\TestParameter::query()->create(['code' => 'PAR-BOD', 'name' => 'BOD', 'is_active' => true]);

        $this->actingAs($this->petugasLabKesling)
            ->post(route('envlab-master.baku-mutu.simpan'), [
                'sample_type_id' => $sampel->id, 'parameter_id' => $parameter->id, 'max_value' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('envlab.quality_standards', [
            'sample_type_id' => $sampel->id, 'parameter_id' => $parameter->id,
        ]);
    }

    #[Test]
    public function petugas_daftar_ditolak_mengakses_layar_data_master_lab_kesling(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-lab-kesling', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)
            ->get(route('envlab-master.index'))
            ->assertForbidden();
    }
}
