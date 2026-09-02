<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\Tariff;
use App\Modules\Catalog\Services\TariffService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private TariffService $tariffs;
    private User $adminMaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->tariffs = app(TariffService::class);

        $this->adminMaster = User::query()->create([
            'username' => 'uji-admin-master', 'name' => 'Admin Master Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMaster->roles()->attach(Role::query()->where('code', 'admin-master')->firstOrFail());
    }

    #[Test]
    public function layar_data_master_hanya_untuk_admin_master(): void
    {
        $this->actingAs($this->adminMaster)->get(route('master.index'))->assertOk();

        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-master', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)->get(route('master.index'))->assertForbidden();
    }

    #[Test]
    public function admin_master_dapat_menambah_penjamin_baru(): void
    {
        $this->actingAs($this->adminMaster)
            ->post(route('master.penjamin.simpan'), [
                'code' => 'INHEALTH2', 'name' => 'Mandiri Inhealth Korporat', 'kind' => 'asuransi',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('catalog.payers', ['code' => 'INHEALTH2', 'kind' => 'asuransi']);
    }

    #[Test]
    public function kode_penjamin_ganda_ditolak_validasi(): void
    {
        $this->actingAs($this->adminMaster)
            ->post(route('master.penjamin.simpan'), ['code' => 'UMUM', 'name' => 'Umum Lagi', 'kind' => 'umum'])
            ->assertSessionHasErrors('code');
    }

    #[Test]
    public function tarif_baru_menutup_tarif_lama_bukan_menimpanya(): void
    {
        $service = Service::query()->where('code', 'REG-RALAN')->firstOrFail();
        $payer = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $lama = Tariff::query()->where('service_id', $service->id)->where('payer_id', $payer->id)->whereNull('valid_until')->firstOrFail();
        $this->assertSame('50000.00', $lama->amount);

        $this->tariffs->setRate($service->id, $payer->id, '-', 60000, 40000, now()->addDay()->toDateString());

        $lama->refresh();
        $this->assertNotNull($lama->valid_until, 'Tarif lama harus tertutup, bukan hilang.');
        $this->assertSame(now()->toDateString(), $lama->valid_until->toDateString());

        $baru = Tariff::query()->where('service_id', $service->id)->where('payer_id', $payer->id)->whereNull('valid_until')->firstOrFail();
        $this->assertSame('60000.00', $baru->amount);
        $this->assertNotSame($lama->id, $baru->id);
    }

    #[Test]
    public function tarif_baru_tidak_boleh_mulai_sebelum_atau_sama_dengan_tarif_berjalan(): void
    {
        $service = Service::query()->where('code', 'REG-RALAN')->firstOrFail();
        $payer = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $berjalan = Tariff::query()
            ->where('service_id', $service->id)->where('payer_id', $payer->id)
            ->whereNull('valid_until')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak boleh ditutup');

        // Tanggal mulai sama persis dengan tarif berjalan — harus ditolak,
        // bukan hanya tanggal yang lebih awal dari itu.
        $this->tariffs->setRate($service->id, $payer->id, '-', 70000, null, $berjalan->valid_from->toDateString());
    }

    #[Test]
    public function riwayat_tarif_lama_tetap_utuh_setelah_beberapa_kali_perubahan(): void
    {
        $service = Service::query()->where('code', 'REG-RALAN')->firstOrFail();
        $payer = Payer::query()->where('code', 'UMUM')->firstOrFail();

        $this->tariffs->setRate($service->id, $payer->id, '-', 55000, null, now()->addDays(10)->toDateString());
        $this->tariffs->setRate($service->id, $payer->id, '-', 60000, null, now()->addDays(20)->toDateString());

        $this->assertSame(3, Tariff::query()->where('service_id', $service->id)->where('payer_id', $payer->id)->count());
        $this->assertSame(1, Tariff::query()->where('service_id', $service->id)->where('payer_id', $payer->id)->whereNull('valid_until')->count());
    }
}
