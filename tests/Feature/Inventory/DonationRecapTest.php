<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\DonationService;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryRecapService;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain E item D (terakhir): hibah barang non-medis dan rekap gabungan
 * 14 kode. Mengikuti bentuk tests/Feature/Pharmacy/RetailTest.php
 * (bagian hibah) disederhanakan ke model non-batch inventory.
 */
class DonationRecapTest extends TestCase
{
    use RefreshDatabase;

    private DonationService $donations;
    private InventoryRecapService $recap;
    private PurchaseOrderService $po;
    private GoodsReceiptService $penerimaan;
    private ItemService $items;
    private StockLedger $ledger;

    private User $petugas;
    private Item $barang;
    private Supplier $suplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->donations = app(DonationService::class);
        $this->recap = app(InventoryRecapService::class);
        $this->po = app(PurchaseOrderService::class);
        $this->penerimaan = app(GoodsReceiptService::class);
        $this->items = app(ItemService::class);
        $this->ledger = app(StockLedger::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-hibah-logistik', 'name' => 'Petugas Hibah Uji', 'password' => 'password', 'is_active' => true,
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
    public function hibah_diterima_menambah_stok_lewat_source_hibah(): void
    {
        $donor = $this->donations->createDonor(['code' => 'DNR-01', 'name' => 'Yayasan Peduli RS', 'is_active' => true]);

        $hibah = $this->donations->receive($donor->id, [$this->barang->id => 20], 'Sumbangan alat tulis', $this->petugas);

        $this->assertEqualsWithDelta(20.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
        $this->assertDatabaseHas('inventory.stock_movements', [
            'item_id' => $this->barang->id, 'kind' => 'masuk', 'source' => 'hibah', 'quantity' => 20,
        ]);
        $this->assertSame($donor->id, $hibah->donor_id);
    }

    #[Test]
    public function pengeluaran_harian_tidak_ikut_menghitung_hibah(): void
    {
        $donor = $this->donations->createDonor(['code' => 'DNR-01', 'name' => 'Yayasan Peduli RS', 'is_active' => true]);
        $this->donations->receive($donor->id, [$this->barang->id => 20], null, $this->petugas);

        $hariIni = now()->toDateString();
        $this->assertEqualsWithDelta(0.0, $this->recap->pengeluaranHarian($hariIni), 0.001);
    }

    #[Test]
    public function rekap_pengadaan_dan_penerimaan_menghitung_po_sungguhan(): void
    {
        $po = $this->po->create($this->suplier->id, [
            $this->barang->id => ['quantity' => 25, 'unit_of_measure' => 'rim', 'unit_price' => 50000],
        ], $this->petugas->id);
        $po = $this->po->submit($po)->load('items');

        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'item_id' => $this->barang->id, 'quantity' => 25,
        ]], $this->petugas);

        $dari = now()->startOfMonth()->toDateString();
        $sampai = now()->toDateString();

        $ringkasanPengadaan = $this->recap->pengadaanRingkasan($dari, $sampai);
        $this->assertSame(1, $ringkasanPengadaan['total_po']);
        $this->assertEqualsWithDelta(1250000.0, $ringkasanPengadaan['total_nilai'], 0.01);

        $ringkasanPenerimaan = $this->recap->penerimaanRingkasan($dari, $sampai);
        $this->assertCount(1, $ringkasanPenerimaan);
        $this->assertEqualsWithDelta(1250000.0, (float) $ringkasanPenerimaan->first()->nilai, 0.01);

        $hariIni = now()->toDateString();
        $this->assertEqualsWithDelta(1250000.0, $this->recap->pengeluaranHarian($hariIni), 0.01);
    }

    #[Test]
    public function rekap_stok_keluar_mengelompokkan_per_sumber(): void
    {
        $this->ledger->receive($this->barang->id, 100, 'pembelian', actor: $this->petugas);
        $this->ledger->issue($this->barang->id, 10, 'permintaan-unit', actor: $this->petugas);

        $dari = now()->startOfMonth()->toDateString();
        $sampai = now()->toDateString();

        $stokKeluar = $this->recap->stokKeluarRingkasan($dari, $sampai);
        $baris = $stokKeluar->firstWhere('source', 'permintaan-unit');
        $this->assertNotNull($baris);
        $this->assertEqualsWithDelta(10.0, (float) $baris->total_keluar, 0.001);
    }

    #[Test]
    public function layar_hibah_dan_rekap_hanya_untuk_petugas_logistik(): void
    {
        $this->actingAs($this->petugas)->get(route('inventory.hibah.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('inventory.rekap.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-hibah-logistik', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('inventory.hibah.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('inventory.rekap.index'))->assertForbidden();
    }

    #[Test]
    public function hibah_bisa_disubmit_lewat_http(): void
    {
        $donor = $this->donations->createDonor(['code' => 'DNR-01', 'name' => 'Yayasan Peduli RS', 'is_active' => true]);

        $this->actingAs($this->petugas)->post(route('inventory.hibah.simpan'), [
            'donor_id' => $donor->id,
            'item_id' => [$this->barang->id],
            'quantity' => [15],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory.donation_receipts', ['donor_id' => $donor->id]);
        $this->assertEqualsWithDelta(15.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
    }
}
