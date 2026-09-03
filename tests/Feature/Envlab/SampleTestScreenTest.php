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

class SampleTestScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;
    private User $penyelia;
    private int $pelangganId;
    private int $jenisSampelId;
    private int $parameterId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->petugas = User::query()->create([
            'username' => 'uji-envlab-http', 'name' => 'Petugas Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-lab-kesling')->firstOrFail());

        $this->penyelia = User::query()->create([
            'username' => 'uji-penyelia-http', 'name' => 'Penyelia Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->penyelia->roles()->attach(Role::query()->where('code', 'penyelia-lab-kesling')->firstOrFail());

        $master = app(EnvlabMasterDataService::class);
        $this->pelangganId = $master->createCustomer(['code' => 'PLG-001', 'name' => 'PT Uji', 'kind' => 'eksternal', 'is_active' => true])->id;
        $this->jenisSampelId = $master->createSampleType(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah', 'is_active' => true])->id;
        $this->parameterId = $master->createParameter(['code' => 'PAR-BOD', 'name' => 'BOD', 'unit' => 'mg/L', 'is_active' => true])->id;
    }

    #[Test]
    public function petugas_dapat_mengajukan_permintaan_lewat_layar(): void
    {
        $this->actingAs($this->petugas)
            ->post(route('envlab-tests.simpan'), [
                'customer_id' => $this->pelangganId,
                'sample_type_id' => $this->jenisSampelId,
                'parameter_ids' => [$this->parameterId],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('envlab.sample_tests', [
            'customer_id' => $this->pelangganId, 'sample_type_id' => $this->jenisSampelId,
        ]);
    }

    #[Test]
    public function penyelia_dapat_menerima_dan_memverifikasi_alur_lengkap_lewat_layar(): void
    {
        $permintaan = app(SampleTestService::class)->request(
            $this->pelangganId, $this->jenisSampelId, [$this->parameterId], null, $this->petugas
        );

        $this->actingAs($this->penyelia)
            ->post(route('envlab-tests.terima', $permintaan), ['assigned_to_name' => 'Analis Sari'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $item = $permintaan->fresh()->items()->first();

        $this->actingAs($this->petugas)
            ->post(route('envlab-tests.hasil.simpan', $item), ['result_value' => 20])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->actingAs($this->penyelia)
            ->post(route('envlab-tests.verifikasi', $permintaan))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->actingAs($this->penyelia)
            ->post(route('envlab-tests.validasi', $permintaan))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame('selesai', $permintaan->fresh()->status);
    }

    #[Test]
    public function petugas_ditolak_menerima_dan_menugaskan_sampel(): void
    {
        $permintaan = app(SampleTestService::class)->request(
            $this->pelangganId, $this->jenisSampelId, [$this->parameterId], null, $this->petugas
        );

        $this->actingAs($this->petugas)
            ->post(route('envlab-tests.terima', $permintaan), ['assigned_to_name' => 'Analis Sari'])
            ->assertForbidden();
    }

    #[Test]
    public function petugas_daftar_ditolak_mengakses_daftar_pengujian(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-envlab', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)
            ->get(route('envlab-tests.index'))
            ->assertForbidden();
    }
}
