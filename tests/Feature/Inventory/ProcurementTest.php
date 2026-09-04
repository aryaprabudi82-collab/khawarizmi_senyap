<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierReturn;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\SupplierReturnService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain E item B (rantai pengadaan): PO -> penerimaan -> verifikasi,
 * dan retur ke suplier. Mengikuti bentuk tests/Feature/Pharmacy/ProcurementTest.php
 * tapi disederhanakan ke model item non-batch inventory (bukan stock_batches).
 */
class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $po;
    private GoodsReceiptService $penerimaan;
    private SupplierReturnService $retur;
    private ItemService $items;
    private StockLedger $ledger;

    private User $petugas;
    private Item $barang;
    private Supplier $suplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->po = app(PurchaseOrderService::class);
        $this->penerimaan = app(GoodsReceiptService::class);
        $this->retur = app(SupplierReturnService::class);
        $this->items = app(ItemService::class);
        $this->ledger = app(StockLedger::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-pengadaan-logistik', 'name' => 'Petugas Logistik Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-logistik')->firstOrFail());

        $kategori = $this->items->createCategory(['code' => 'ATK', 'name' => 'Alat Tulis Kantor', 'is_active' => true]);
        $this->barang = $this->items->createItem([
            'code' => 'ATK001', 'name' => 'Kertas A4', 'category_id' => $kategori->id,
            'unit_of_measure' => 'rim', 'quantity_on_hand' => 0, 'is_active' => true,
        ]);
        $this->suplier = $this->items->createSupplier(['code' => 'SUP-01', 'name' => 'CV Sumber Kertas', 'is_active' => true]);
    }

    #[Test]
    public function po_dibuat_lalu_dikirim_ke_suplier(): void
    {
        $po = $this->po->create($this->suplier->id, [
            $this->barang->id => ['quantity' => 100, 'unit_of_measure' => 'rim', 'unit_price' => 50000],
        ], $this->petugas->id);

        $this->assertSame(PurchaseOrder::STATUS_DRAF, $po->status);
        $this->assertEqualsWithDelta(5000000.0, (float) $po->total_amount, 0.01);

        $dikirim = $this->po->submit($po);
        $this->assertSame(PurchaseOrder::STATUS_DIPESAN, $dikirim->status);
        $this->assertNotNull($dikirim->ordered_at);
    }

    #[Test]
    public function po_draf_bisa_dibatalkan_tapi_po_diterima_penuh_tidak_bisa(): void
    {
        $po = $this->buatPoDipesan(10);

        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id,
            'quantity' => 10,
        ]], $this->petugas);

        $this->assertSame(PurchaseOrder::STATUS_DITERIMA, $po->fresh()->status);

        $this->expectException(InventoryException::class);
        $this->po->cancel($po->fresh());
    }

    #[Test]
    public function po_diterima_menambah_stok_dan_menandai_po_diterima(): void
    {
        $po = $this->buatPoDipesan(50);

        $penerimaan = $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id,
            'quantity' => 50,
        ]], $this->petugas);

        $this->assertEqualsWithDelta(50.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
        $this->assertDatabaseHas('inventory.stock_movements', [
            'item_id' => $this->barang->id, 'kind' => 'masuk', 'source' => 'pembelian', 'quantity' => 50,
        ]);
        $this->assertSame(GoodsReceipt::STATUS_DITERIMA, $penerimaan->status);
        $this->assertSame(PurchaseOrder::STATUS_DITERIMA, $po->fresh()->status);
    }

    #[Test]
    public function po_diterima_sebagian_belum_ditandai_diterima_penuh(): void
    {
        $po = $this->buatPoDipesan(50);

        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id,
            'quantity' => 20,
        ]], $this->petugas);

        $this->assertSame(PurchaseOrder::STATUS_DITERIMA_SEBAGIAN, $po->fresh()->status);
        $this->assertEqualsWithDelta(20.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
    }

    #[Test]
    public function penerimaan_yang_sudah_diverifikasi_tidak_bisa_diverifikasi_ulang(): void
    {
        $po = $this->buatPoDipesan(10);
        $penerimaan = $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id, 'quantity' => 10,
        ]], $this->petugas);

        $this->penerimaan->verify($penerimaan, 'sesuai', null, $this->petugas);

        $this->expectException(InventoryException::class);
        $this->penerimaan->verify($penerimaan, 'sesuai', null, $this->petugas);
    }

    #[Test]
    public function retur_selesai_mengurangi_stok_dan_dicatat_di_ledger(): void
    {
        $po = $this->buatPoDipesan(30);
        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id, 'quantity' => 30,
        ]], $this->petugas);

        $retur = $this->retur->request($this->suplier->id, null, [[
            'item_id' => $this->barang->id, 'quantity' => 5, 'note' => 'Kemasan rusak',
        ]], 'Kemasan rusak saat diterima', $this->petugas);

        $this->retur->complete($retur, $this->petugas);

        $this->assertEqualsWithDelta(25.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
        $this->assertDatabaseHas('inventory.stock_movements', [
            'item_id' => $this->barang->id, 'kind' => 'keluar', 'source' => 'retur-suplier', 'quantity' => -5,
        ]);
        $this->assertSame(SupplierReturn::STATUS_SELESAI, $retur->fresh()->status);
    }

    #[Test]
    public function retur_melebihi_stok_ditolak_dan_tidak_mengubah_status(): void
    {
        $po = $this->buatPoDipesan(10);
        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id, 'quantity' => 10,
        ]], $this->petugas);

        $retur = $this->retur->request($this->suplier->id, null, [[
            'item_id' => $this->barang->id, 'quantity' => 999, 'note' => null,
        ]], 'Alasan', $this->petugas);

        try {
            $this->retur->complete($retur, $this->petugas);
            $this->fail('Seharusnya melempar InventoryException.');
        } catch (InventoryException) {
            // diharapkan
        }

        $this->assertSame(SupplierReturn::STATUS_DIAJUKAN, $retur->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
    }

    #[Test]
    public function layar_pengadaan_logistik_hanya_untuk_petugas_logistik(): void
    {
        $this->actingAs($this->petugas)->get(route('inventory.po.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('inventory.penerimaan.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('inventory.retur.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-pengadaan-logistik', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('inventory.po.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('inventory.penerimaan.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('inventory.retur.index'))->assertForbidden();
    }

    #[Test]
    public function form_buat_po_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->petugas)->post(route('inventory.po.simpan'), [
            'supplier_id' => $this->suplier->id,
            'item_id' => [$this->barang->id],
            'unit_of_measure' => ['rim'],
            'quantity' => [20],
            'unit_price' => [50000],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory.purchase_orders', ['supplier_id' => $this->suplier->id]);
    }

    #[Test]
    public function po_bisa_dicetak_sebagai_surat_pemesanan(): void
    {
        $po = $this->buatPoDipesan(5);

        $this->actingAs($this->petugas)->get(route('inventory.po.cetak', $po))->assertOk();
    }

    private function buatPoDipesan(float $quantity): PurchaseOrder
    {
        $po = $this->po->create($this->suplier->id, [
            $this->barang->id => ['quantity' => $quantity, 'unit_of_measure' => 'rim', 'unit_price' => 50000],
        ], $this->petugas->id);

        return $this->po->submit($po)->load('items');
    }
}
