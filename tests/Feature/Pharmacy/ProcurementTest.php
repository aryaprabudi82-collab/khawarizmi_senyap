<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugRequisition;
use App\Modules\Pharmacy\Models\GoodsReceipt;
use App\Modules\Pharmacy\Models\PurchaseOrder;
use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\Supplier;
use App\Modules\Pharmacy\Models\SupplierReturn;
use App\Modules\Pharmacy\Services\DrugRequisitionService;
use App\Modules\Pharmacy\Services\GoodsReceiptService;
use App\Modules\Pharmacy\Services\MasterDataService;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\PurchaseOrderService;
use App\Modules\Pharmacy\Services\SupplierReturnService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private DrugRequisitionService $pengajuan;
    private PurchaseOrderService $po;
    private GoodsReceiptService $penerimaan;
    private SupplierReturnService $retur;
    private MasterDataService $master;

    private User $apoteker;
    private Drug $obat;
    private Supplier $suplier;
    private Unit $unitFarmasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, PharmacySeeder::class]);

        $this->pengajuan = app(DrugRequisitionService::class);
        $this->po = app(PurchaseOrderService::class);
        $this->penerimaan = app(GoodsReceiptService::class);
        $this->retur = app(SupplierReturnService::class);
        $this->master = app(MasterDataService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-pengadaan', 'name' => 'Apoteker Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
        $this->suplier = $this->master->createSupplier(['code' => 'SUP-01', 'name' => 'PBF Kimia Farma', 'is_active' => true]);
        $this->unitFarmasi = Unit::query()->where('code', 'FARMASI')->firstOrFail();
    }

    #[Test]
    public function pengajuan_disetujui_lalu_po_dibuat_darinya(): void
    {
        $pengajuan = $this->pengajuan->request(
            $this->unitFarmasi->id, $this->unitFarmasi->name,
            [$this->obat->id => 100], null, $this->apoteker->id,
        );
        $disetujui = $this->pengajuan->approve($pengajuan, $this->apoteker->id);

        $po = $this->po->create($this->suplier->id, [
            $this->obat->id => ['quantity' => 100, 'unit' => 'boks', 'unit_price' => 15000],
        ], $this->apoteker->id, $disetujui);

        $this->assertSame(DrugRequisition::STATUS_DISETUJUI, $disetujui->status);
        $this->assertSame($pengajuan->id, $po->requisition_id);
        $this->assertEqualsWithDelta(1500000.0, (float) $po->total_amount, 0.01);
    }

    #[Test]
    public function pengajuan_yang_sudah_diputuskan_tidak_bisa_diputuskan_ulang(): void
    {
        $pengajuan = $this->pengajuan->request($this->unitFarmasi->id, $this->unitFarmasi->name, [$this->obat->id => 10], null, $this->apoteker->id);
        $this->pengajuan->approve($pengajuan, $this->apoteker->id);

        $this->expectException(PharmacyException::class);
        $this->pengajuan->reject($pengajuan, $this->apoteker->id, 'alasan');
    }

    #[Test]
    public function po_diterima_menambah_stok_batch_dan_menandai_po_diterima(): void
    {
        $po = $this->buatPoDipesan(50);

        $penerimaan = $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'drug_id' => $this->obat->id,
            'quantity' => 50,
            'batch_number' => 'BATCH-PO-1',
            'expiry_date' => now()->addYear()->toDateString(),
            'cost_price' => 15000,
        ]], ['invoice_number' => 'INV-001', 'paid_amount' => 750000], $this->apoteker);

        $batch = StockBatch::query()->where('drug_id', $this->obat->id)->where('batch_number', 'BATCH-PO-1')->firstOrFail();
        $this->assertEqualsWithDelta(50.0, (float) $batch->quantity_on_hand, 0.01);

        $this->assertSame(GoodsReceipt::PAYMENT_LUNAS, $penerimaan->payment_status);
        $this->assertSame(PurchaseOrder::STATUS_DITERIMA, $po->fresh()->status);
    }

    #[Test]
    public function po_diterima_sebagian_belum_ditandai_diterima_penuh(): void
    {
        $po = $this->buatPoDipesan(50);

        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'drug_id' => $this->obat->id,
            'quantity' => 20,
            'batch_number' => 'BATCH-PO-2',
            'expiry_date' => null,
            'cost_price' => 15000,
        ]], [], $this->apoteker);

        $this->assertSame(PurchaseOrder::STATUS_DITERIMA_SEBAGIAN, $po->fresh()->status);
    }

    #[Test]
    public function penerimaan_yang_sudah_diverifikasi_tidak_bisa_diverifikasi_ulang(): void
    {
        $po = $this->buatPoDipesan(10);
        $penerimaan = $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'drug_id' => $this->obat->id, 'quantity' => 10, 'batch_number' => 'BATCH-PO-3', 'expiry_date' => null, 'cost_price' => 15000,
        ]], [], $this->apoteker);

        $this->penerimaan->verify($penerimaan, 'sesuai', null, $this->apoteker);

        $this->expectException(PharmacyException::class);
        $this->penerimaan->verify($penerimaan, 'sesuai', null, $this->apoteker);
    }

    #[Test]
    public function retur_selesai_mengurangi_stok_batch_terkait(): void
    {
        $po = $this->buatPoDipesan(30);
        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'drug_id' => $this->obat->id, 'quantity' => 30, 'batch_number' => 'BATCH-RETUR', 'expiry_date' => null, 'cost_price' => 15000,
        ]], [], $this->apoteker);

        $retur = $this->retur->request($this->suplier->id, null, [[
            'drug_id' => $this->obat->id, 'batch_number' => 'BATCH-RETUR', 'quantity' => 5, 'note' => 'Kemasan rusak',
        ]], 'Kemasan rusak saat diterima', $this->apoteker);

        $this->retur->complete($retur, $this->apoteker);

        $batch = StockBatch::query()->where('batch_number', 'BATCH-RETUR')->firstOrFail();
        $this->assertEqualsWithDelta(25.0, (float) $batch->quantity_on_hand, 0.01);
        $this->assertSame(SupplierReturn::STATUS_SELESAI, $retur->fresh()->status);
    }

    #[Test]
    public function retur_melebihi_stok_batch_ditolak(): void
    {
        $po = $this->buatPoDipesan(10);
        $this->penerimaan->receive($po, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'drug_id' => $this->obat->id, 'quantity' => 10, 'batch_number' => 'BATCH-RETUR-2', 'expiry_date' => null, 'cost_price' => 15000,
        ]], [], $this->apoteker);

        $retur = $this->retur->request($this->suplier->id, null, [[
            'drug_id' => $this->obat->id, 'batch_number' => 'BATCH-RETUR-2', 'quantity' => 999, 'note' => null,
        ]], 'Alasan', $this->apoteker);

        $this->expectException(PharmacyException::class);
        $this->retur->complete($retur, $this->apoteker);
    }

    #[Test]
    public function layar_pengadaan_farmasi_hanya_untuk_apoteker(): void
    {
        $this->actingAs($this->apoteker)->get(route('pharmacy.pengajuan.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.po.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.penerimaan.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.retur.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-pengadaan', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('pharmacy.pengajuan.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.po.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.penerimaan.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.retur.index'))->assertForbidden();
    }

    #[Test]
    public function form_buat_po_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->apoteker)->post(route('pharmacy.po.simpan'), [
            'supplier_id' => $this->suplier->id,
            'drug_id' => [$this->obat->id],
            'unit' => ['boks'],
            'quantity' => [20],
            'unit_price' => [15000],
        ])->assertRedirect();

        $this->assertDatabaseHas('pharmacy.purchase_orders', ['supplier_id' => $this->suplier->id]);
    }

    private function buatPoDipesan(float $quantity): PurchaseOrder
    {
        $po = $this->po->create($this->suplier->id, [
            $this->obat->id => ['quantity' => $quantity, 'unit' => 'boks', 'unit_price' => 15000],
        ], $this->apoteker->id);

        return $this->po->submit($po)->load('items');
    }
}
