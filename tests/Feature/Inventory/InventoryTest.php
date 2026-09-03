<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Requisition;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\RequisitionService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private ItemService $items;
    private StockLedger $ledger;
    private RequisitionService $requisitions;
    private User $petugas;
    private Item $barang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->items = app(ItemService::class);
        $this->ledger = app(StockLedger::class);
        $this->requisitions = app(RequisitionService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-logistik', 'name' => 'Petugas Logistik Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-logistik')->firstOrFail());

        $kategori = $this->items->createCategory(['code' => 'ATK', 'name' => 'Alat Tulis Kantor', 'is_active' => true]);
        $this->barang = $this->items->createItem([
            'code' => 'ATK001', 'name' => 'Kertas A4', 'category_id' => $kategori->id,
            'unit_of_measure' => 'rim', 'quantity_on_hand' => 0, 'is_active' => true,
        ]);
    }

    #[Test]
    public function stok_masuk_menambah_saldo_dan_tercatat_di_ledger(): void
    {
        $this->ledger->receive($this->barang->id, 50, 'pembelian', actor: $this->petugas);

        $this->assertEqualsWithDelta(50.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);

        $cocok = $this->ledger->reconcile($this->barang->id);
        $this->assertTrue($cocok['cocok']);
    }

    #[Test]
    public function stok_keluar_ditolak_kalau_tidak_cukup(): void
    {
        $this->ledger->receive($this->barang->id, 10, 'pembelian', actor: $this->petugas);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('tidak cukup');

        $this->ledger->issue($this->barang->id, 20, 'permintaan-unit', actor: $this->petugas);
    }

    #[Test]
    public function opname_menyesuaikan_saldo_dan_mencatat_selisih(): void
    {
        $this->ledger->receive($this->barang->id, 30, 'pembelian', actor: $this->petugas);
        $this->ledger->opname($this->barang->id, 25, $this->petugas, 'Hasil hitung fisik akhir bulan');

        $this->assertEqualsWithDelta(25.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
        $this->assertDatabaseHas('inventory.stock_movements', ['item_id' => $this->barang->id, 'kind' => 'opname', 'quantity' => -5]);
    }

    #[Test]
    public function permintaan_barang_lengkap_dari_diajukan_sampai_selesai(): void
    {
        $this->ledger->receive($this->barang->id, 100, 'pembelian', actor: $this->petugas);
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $permintaan = $this->requisitions->request($unit->id, $unit->name, [$this->barang->id => 15], 'Untuk stok poli', $this->petugas->id);
        $this->assertSame(Requisition::STATUS_DIAJUKAN, $permintaan->status);

        $disetujui = $this->requisitions->approve($permintaan, $this->petugas->id);
        $this->assertSame(Requisition::STATUS_DISETUJUI, $disetujui->status);

        $selesai = $this->requisitions->fulfill($disetujui, $this->petugas);
        $this->assertSame(Requisition::STATUS_SELESAI, $selesai->status);
        $this->assertEqualsWithDelta(85.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
    }

    #[Test]
    public function permintaan_gagal_dikeluarkan_kalau_stok_tidak_cukup_tidak_mengubah_apapun(): void
    {
        $this->ledger->receive($this->barang->id, 5, 'pembelian', actor: $this->petugas);
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $permintaan = $this->requisitions->approve(
            $this->requisitions->request($unit->id, $unit->name, [$this->barang->id => 20], null, $this->petugas->id),
            $this->petugas->id
        );

        try {
            $this->requisitions->fulfill($permintaan, $this->petugas);
            $this->fail('Seharusnya melempar InventoryException.');
        } catch (InventoryException) {
            // diharapkan
        }

        $this->assertSame(Requisition::STATUS_DISETUJUI, $permintaan->refresh()->status);
        $this->assertEqualsWithDelta(5.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
    }

    #[Test]
    public function permintaan_yang_ditolak_tidak_bisa_dikeluarkan(): void
    {
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $permintaan = $this->requisitions->reject(
            $this->requisitions->request($unit->id, $unit->name, [$this->barang->id => 5], null, $this->petugas->id),
            $this->petugas->id,
            'Barang sedang kosong dari suplier'
        );

        $this->expectException(InventoryException::class);

        $this->requisitions->fulfill($permintaan, $this->petugas);
    }

    #[Test]
    public function layar_logistik_hanya_untuk_petugas_logistik(): void
    {
        $this->actingAs($this->petugas)->get(route('inventory.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('inventory.permintaan.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-logistik', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('inventory.index'))->assertForbidden();
    }
}
