<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Inventory\Services\StockReportService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain E item C: sesi stok opname multi-barang dan layar gabungan
 * riwayat/sirkulasi. Mengikuti bentuk tests/Feature/Pharmacy/StockOpsTest.php
 * yang disederhanakan ke model item non-batch/non-lokasi inventory.
 */
class StockOpnameReportTest extends TestCase
{
    use RefreshDatabase;

    private StockOpnameService $opnames;
    private StockReportService $reports;
    private ItemService $items;
    private StockLedger $ledger;

    private User $petugas;
    private Item $barangA;
    private Item $barangB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->opnames = app(StockOpnameService::class);
        $this->reports = app(StockReportService::class);
        $this->items = app(ItemService::class);
        $this->ledger = app(StockLedger::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-opname-logistik', 'name' => 'Petugas Opname Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-logistik')->firstOrFail());

        $kategori = $this->items->createCategory(['code' => 'ATK', 'name' => 'Alat Tulis Kantor', 'is_active' => true]);
        $this->barangA = $this->items->createItem([
            'code' => 'ATK001', 'name' => 'Kertas A4', 'category_id' => $kategori->id,
            'unit_of_measure' => 'rim', 'quantity_on_hand' => 0, 'is_active' => true,
        ]);
        $this->barangB = $this->items->createItem([
            'code' => 'ATK002', 'name' => 'Tinta Printer', 'category_id' => $kategori->id,
            'unit_of_measure' => 'botol', 'quantity_on_hand' => 0, 'is_active' => true,
        ]);

        $this->ledger->receive($this->barangA->id, 100, 'pembelian', actor: $this->petugas);
        $this->ledger->receive($this->barangB->id, 20, 'pembelian', actor: $this->petugas);
    }

    #[Test]
    public function sesi_opname_dibuka_menyiapkan_seluruh_barang_aktif(): void
    {
        $opname = $this->opnames->start('Opname akhir bulan', $this->petugas->id);

        $this->assertSame(StockOpname::STATUS_DRAF, $opname->status);
        $this->assertSame(2, $opname->items->count());

        $barisA = $opname->items->firstWhere('item_id', $this->barangA->id);
        $this->assertEqualsWithDelta(100.0, (float) $barisA->system_quantity, 0.001);
    }

    #[Test]
    public function opname_selesai_menyesuaikan_selisih_lewat_ledger(): void
    {
        $opname = $this->opnames->start(null, $this->petugas->id);
        $barisA = $opname->items->firstWhere('item_id', $this->barangA->id);
        $barisB = $opname->items->firstWhere('item_id', $this->barangB->id);

        $this->opnames->recordCount($opname, $barisA->id, 95, 'Susut 5 rim');
        // barisB tidak dihitung -> dianggap sesuai, dilewati.

        $this->opnames->complete($opname, $this->petugas);

        $this->assertEqualsWithDelta(95.0, (float) $this->barangA->refresh()->quantity_on_hand, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $this->barangB->refresh()->quantity_on_hand, 0.001);
        $this->assertDatabaseHas('inventory.stock_movements', [
            'item_id' => $this->barangA->id, 'kind' => 'opname', 'quantity' => -5,
        ]);
        $this->assertSame(StockOpname::STATUS_SELESAI, $opname->fresh()->status);
    }

    #[Test]
    public function opname_yang_sudah_selesai_tidak_bisa_diselesaikan_ulang(): void
    {
        $opname = $this->opnames->start(null, $this->petugas->id);
        $this->opnames->complete($opname, $this->petugas);

        $this->expectException(InventoryException::class);
        $this->opnames->complete($opname->fresh(), $this->petugas);
    }

    #[Test]
    public function hitung_tidak_bisa_diisi_setelah_opname_selesai(): void
    {
        $opname = $this->opnames->start(null, $this->petugas->id);
        $barisA = $opname->items->firstWhere('item_id', $this->barangA->id);
        $this->opnames->complete($opname, $this->petugas);

        $this->expectException(InventoryException::class);
        $this->opnames->recordCount($opname->fresh(), $barisA->id, 50);
    }

    #[Test]
    public function riwayat_dan_sirkulasi_membaca_pergerakan_barang(): void
    {
        $riwayat = $this->reports->riwayat($this->barangA->id);
        $this->assertCount(1, $riwayat);
        $this->assertSame('pembelian', $riwayat->first()->source);

        $sirkulasi = $this->reports->sirkulasi(['item_id' => $this->barangA->id]);
        $this->assertCount(1, $sirkulasi);

        $bulanan = $this->reports->sirkulasiBulanan(now()->format('Y-m'));
        $baris = $bulanan->firstWhere('item_id', $this->barangA->id);
        $this->assertEqualsWithDelta(100.0, (float) $baris->total_masuk, 0.001);
    }

    #[Test]
    public function layar_opname_dan_laporan_hanya_untuk_petugas_logistik(): void
    {
        $this->actingAs($this->petugas)->get(route('inventory.opname.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('inventory.laporan.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-opname-logistik', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('inventory.opname.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('inventory.laporan.index'))->assertForbidden();
    }

    #[Test]
    public function sesi_opname_bisa_dibuka_dan_diselesaikan_lewat_http(): void
    {
        $opname = $this->opnames->start(null, $this->petugas->id);
        $barisA = $opname->items->firstWhere('item_id', $this->barangA->id);

        $this->actingAs($this->petugas)->post(route('inventory.opname.hitung', $opname), [
            'baris_id' => [$barisA->id],
            'counted_quantity' => [90],
        ])->assertRedirect();

        $this->actingAs($this->petugas)->post(route('inventory.opname.selesai', $opname))->assertRedirect();

        $this->assertEqualsWithDelta(90.0, (float) $this->barangA->refresh()->quantity_on_hand, 0.001);
    }
}
