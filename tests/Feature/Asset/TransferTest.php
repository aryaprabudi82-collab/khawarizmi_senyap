<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetService;
use App\Modules\Asset\Services\AssetTransferService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain G item C: sirkulasi/mutasi aset antar lokasi
 * (inventaris_sirkulasi) — riwayat perpindahan satu aset, beda
 * bentuk dari ledger kuantitas inventory/kitchen.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private AssetService $assets;
    private AssetTransferService $transfers;
    private User $petugas;
    private Asset $aset;
    private AssetLocation $lokasiAwal;
    private AssetLocation $lokasiTujuan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->assets = app(AssetService::class);
        $this->transfers = app(AssetTransferService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-sirkulasi-aset', 'name' => 'Petugas Aset Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-aset')->firstOrFail());

        $kategori = $this->assets->createCategory(['code' => 'ELK', 'name' => 'Elektronik']);
        $this->lokasiAwal = $this->assets->createLocation(['code' => 'IGD', 'name' => 'IGD']);
        $this->lokasiTujuan = $this->assets->createLocation(['code' => 'ICU', 'name' => 'ICU']);

        $this->aset = $this->assets->createAsset([
            'name' => 'Monitor Pasien', 'category_id' => $kategori->id, 'location_id' => $this->lokasiAwal->id,
        ]);
    }

    #[Test]
    public function mutasi_memindahkan_aset_dan_mencatat_riwayat(): void
    {
        $mutasi = $this->transfers->transfer($this->aset, $this->lokasiTujuan->id, $this->petugas, 'Pindah ke ICU');

        $this->assertSame($this->lokasiAwal->id, $mutasi->from_location_id);
        $this->assertSame($this->lokasiTujuan->id, $mutasi->to_location_id);
        $this->assertSame($this->lokasiTujuan->id, $this->aset->refresh()->location_id, 'location_id aset harus jadi cache lokasi terkini.');
        $this->assertMatchesRegularExpression('/^MUT-\d{4}-\d{5}$/', $mutasi->transfer_number);
    }

    #[Test]
    public function mutasi_ke_lokasi_yang_sama_ditolak(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('sudah berada di lokasi tujuan');

        $this->transfers->transfer($this->aset, $this->lokasiAwal->id, $this->petugas);
    }

    #[Test]
    public function riwayat_mutasi_terekam_berurutan_untuk_perpindahan_berkali_kali(): void
    {
        $lokasiKetiga = $this->assets->createLocation(['code' => 'RAJAL', 'name' => 'Rawat Jalan']);

        $this->transfers->transfer($this->aset, $this->lokasiTujuan->id, $this->petugas);
        $this->transfers->transfer($this->aset->refresh(), $lokasiKetiga->id, $this->petugas);

        $riwayat = $this->transfers->history($this->aset->refresh());

        $this->assertCount(2, $riwayat);
        $this->assertSame($lokasiKetiga->id, $this->aset->refresh()->location_id);
    }

    #[Test]
    public function layar_sirkulasi_hanya_untuk_petugas_aset(): void
    {
        $this->actingAs($this->petugas)->get(route('asset.sirkulasi.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-sirkulasi-aset', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('asset.sirkulasi.index'))->assertForbidden();
    }

    #[Test]
    public function mutasi_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->petugas)->post(route('asset.sirkulasi.simpan'), [
            'asset_id' => $this->aset->id,
            'to_location_id' => $this->lokasiTujuan->id,
            'notes' => 'Uji HTTP',
        ])->assertRedirect();

        $this->assertSame($this->lokasiTujuan->id, $this->aset->refresh()->location_id);
        $this->assertDatabaseHas('asset.asset_transfers', ['asset_id' => $this->aset->id, 'to_location_id' => $this->lokasiTujuan->id]);
    }
}
