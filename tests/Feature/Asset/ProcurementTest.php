<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\DonationReceipt;
use App\Modules\Asset\Models\PurchaseOrder;
use App\Modules\Asset\Models\Requisition;
use App\Modules\Asset\Models\Supplier;
use App\Modules\Asset\Services\AssetDonationService;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetGoodsReceiptService;
use App\Modules\Asset\Services\AssetPurchaseOrderService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain G item B (rantai pengadaan aset). Beda mendasar dari
 * tests/Feature/Inventory|Kitchen/ProcurementTest.php: penerimaan
 * membuat baris Asset baru per unit, bukan menambah kuantitas —
 * tidak ada StockLedger di sini sama sekali.
 */
class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private AssetPurchaseOrderService $po;
    private AssetGoodsReceiptService $penerimaan;
    private AssetDonationService $donations;

    private User $petugas;
    private AssetCategory $kategori;
    private Supplier $suplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->po = app(AssetPurchaseOrderService::class);
        $this->penerimaan = app(AssetGoodsReceiptService::class);
        $this->donations = app(AssetDonationService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-pengadaan-aset', 'name' => 'Petugas Aset Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-aset')->firstOrFail());

        $this->kategori = AssetCategory::query()->create(['code' => 'ELK', 'name' => 'Elektronik', 'is_active' => true]);
        $this->suplier = Supplier::query()->create(['code' => 'SUP-01', 'name' => 'CV Alat Medis Sejahtera', 'is_active' => true]);
    }

    #[Test]
    public function po_dibuat_lalu_dikirim_ke_suplier(): void
    {
        $po = $this->po->create($this->suplier->id, [
            ['item_name' => 'AC Split 1 PK', 'category_id' => $this->kategori->id, 'type_id' => null, 'manufacturer_id' => null, 'quantity' => 3, 'unit_price' => 5000000],
        ], $this->petugas->id);

        $this->assertSame(PurchaseOrder::STATUS_DRAF, $po->status);
        $this->assertEqualsWithDelta(15000000.0, (float) $po->total_amount, 0.01);

        $dikirim = $this->po->submit($po);
        $this->assertSame(PurchaseOrder::STATUS_DIPESAN, $dikirim->status);
    }

    #[Test]
    public function po_dari_pengajuan_menandai_pengajuan_selesai(): void
    {
        $pengajuan = Requisition::query()->create([
            'requisition_number' => 'PGJ-UJI-1', 'unit_id' => 1, 'unit_name' => 'IGD',
            'status' => Requisition::STATUS_DISETUJUI, 'requested_by' => $this->petugas->id,
        ]);

        $this->po->create($this->suplier->id, [
            ['item_name' => 'Tensimeter Digital', 'category_id' => $this->kategori->id, 'type_id' => null, 'manufacturer_id' => null, 'quantity' => 2, 'unit_price' => 500000],
        ], $this->petugas->id, $pengajuan);

        $this->assertSame(Requisition::STATUS_SELESAI, $pengajuan->fresh()->status);
    }

    #[Test]
    public function penerimaan_membuat_aset_baru_sebanyak_kuantitas_bukan_menambah_kuantitas(): void
    {
        $po = $this->buatPoDipesan(3);

        $penerimaan = $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id, 'quantity' => 3,
        ]], $this->petugas);

        $asetBaru = \App\Modules\Asset\Models\Asset::query()->where('acquisition_value', 5000000)->get();
        $this->assertCount(3, $asetBaru, 'Harus ada 3 baris Asset baru, satu per unit diterima.');
        $this->assertSame(3, $asetBaru->pluck('asset_number')->unique()->count(), 'Tiap unit harus punya asset_number sendiri-sendiri.');
        $this->assertSame(PurchaseOrder::STATUS_DITERIMA, $po->fresh()->status);
        $this->assertNotNull($penerimaan->receipt_number);
    }

    #[Test]
    public function po_diterima_sebagian_belum_ditandai_diterima_penuh(): void
    {
        $po = $this->buatPoDipesan(5);

        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id, 'quantity' => 2,
        ]], $this->petugas);

        $this->assertSame(PurchaseOrder::STATUS_DITERIMA_SEBAGIAN, $po->fresh()->status);

        $asetBaru = \App\Modules\Asset\Models\Asset::query()->where('acquisition_value', 5000000)->get();
        $this->assertCount(2, $asetBaru);
    }

    #[Test]
    public function po_draf_bisa_dibatalkan_tapi_po_diterima_penuh_tidak_bisa(): void
    {
        $po = $this->buatPoDipesan(1);
        $this->penerimaan->receive($po, [['purchase_order_item_id' => $po->items->first()->id, 'quantity' => 1]], $this->petugas);

        $this->assertSame(PurchaseOrder::STATUS_DITERIMA, $po->fresh()->status);

        $this->expectException(AssetException::class);
        $this->po->cancel($po->fresh());
    }

    #[Test]
    public function hibah_langsung_membuat_aset_baru_dengan_nilai_perolehan_nol(): void
    {
        $donor = $this->donations->createDonor(['code' => 'DNR-01', 'name' => 'Yayasan Peduli Alkes', 'is_active' => true]);

        $hibah = $this->donations->receive($donor->id, [
            ['item_name' => 'Kursi Roda', 'category_id' => $this->kategori->id, 'quantity' => 2],
        ], 'Sumbangan kursi roda', $this->petugas);

        $asetBaru = \App\Modules\Asset\Models\Asset::query()->where('name', 'Kursi Roda')->get();
        $this->assertCount(2, $asetBaru);
        $this->assertTrue($asetBaru->every(fn ($a) => (float) $a->acquisition_value === 0.0));
        $this->assertInstanceOf(DonationReceipt::class, $hibah);
    }

    #[Test]
    public function layar_pengadaan_aset_hanya_untuk_petugas_aset(): void
    {
        $this->actingAs($this->petugas)->get(route('asset.pengajuan.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('asset.po.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('asset.hibah.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-pengadaan-aset', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('asset.pengajuan.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('asset.po.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('asset.hibah.index'))->assertForbidden();
    }

    #[Test]
    public function form_buat_po_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->petugas)->post(route('asset.po.simpan'), [
            'supplier_id' => $this->suplier->id,
            'item_name' => ['Lemari Es Vaksin'],
            'category_id' => [$this->kategori->id],
            'quantity' => [1],
            'unit_price' => [8000000],
        ])->assertRedirect();

        $this->assertDatabaseHas('asset.purchase_orders', ['supplier_id' => $this->suplier->id]);
    }

    private function buatPoDipesan(float $quantity): PurchaseOrder
    {
        $po = $this->po->create($this->suplier->id, [
            ['item_name' => 'AC Split 1 PK', 'category_id' => $this->kategori->id, 'type_id' => null, 'manufacturer_id' => null, 'quantity' => $quantity, 'unit_price' => 5000000],
        ], $this->petugas->id);

        return $this->po->submit($po)->load('items');
    }
}
