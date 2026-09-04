<?php

namespace Tests\Feature\Kitchen;

use App\Modules\Kitchen\Models\Item;
use App\Modules\Kitchen\Models\ItemCategory;
use App\Modules\Kitchen\Models\Requisition;
use App\Modules\Kitchen\Services\ItemService;
use App\Modules\Kitchen\Services\KitchenException;
use App\Modules\Kitchen\Services\RequisitionService;
use App\Modules\Kitchen\Services\StockLedger;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain F item A (data master + alur permintaan unit). Mengikuti
 * bentuk tests/Feature/Inventory/InventoryTest.php persis — context
 * kitchen paralel arsitekturnya dengan inventory.
 */
class KitchenTest extends TestCase
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
            'username' => 'uji-dapur', 'name' => 'Petugas Dapur Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-dapur')->firstOrFail());

        $kategori = $this->items->createCategory(['code' => 'SEMBAKO', 'name' => 'Sembako', 'is_active' => true]);
        $this->barang = $this->items->createItem([
            'code' => 'DPR001', 'name' => 'Beras', 'category_id' => $kategori->id,
            'unit_of_measure' => 'kg', 'quantity_on_hand' => 0, 'is_active' => true,
        ]);
    }

    #[Test]
    public function stok_masuk_menambah_saldo_dan_tercatat_di_ledger(): void
    {
        $this->ledger->receive($this->barang->id, 100, 'pembelian', actor: $this->petugas);

        $this->assertEqualsWithDelta(100.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);

        $cocok = $this->ledger->reconcile($this->barang->id);
        $this->assertTrue($cocok['cocok']);
    }

    #[Test]
    public function stok_keluar_ditolak_kalau_tidak_cukup(): void
    {
        $this->ledger->receive($this->barang->id, 10, 'pembelian', actor: $this->petugas);

        $this->expectException(KitchenException::class);
        $this->expectExceptionMessage('tidak cukup');

        $this->ledger->issue($this->barang->id, 20, 'permintaan-unit', actor: $this->petugas);
    }

    #[Test]
    public function opname_menyesuaikan_saldo_dan_mencatat_selisih(): void
    {
        $this->ledger->receive($this->barang->id, 30, 'pembelian', actor: $this->petugas);
        $this->ledger->opname($this->barang->id, 25, $this->petugas, 'Susut hasil hitung fisik');

        $this->assertEqualsWithDelta(25.0, (float) $this->barang->refresh()->quantity_on_hand, 0.001);
        $this->assertDatabaseHas('kitchen.stock_movements', ['item_id' => $this->barang->id, 'kind' => 'opname', 'quantity' => -5]);
    }

    #[Test]
    public function permintaan_barang_lengkap_dari_diajukan_sampai_selesai(): void
    {
        $this->ledger->receive($this->barang->id, 100, 'pembelian', actor: $this->petugas);
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $permintaan = $this->requisitions->request($unit->id, $unit->name, [$this->barang->id => 15], 'Untuk konsumsi pasien', $this->petugas->id);
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
            $this->fail('Seharusnya melempar KitchenException.');
        } catch (KitchenException) {
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

        $this->expectException(KitchenException::class);

        $this->requisitions->fulfill($permintaan, $this->petugas);
    }

    #[Test]
    public function layar_dapur_hanya_untuk_petugas_dapur(): void
    {
        $this->actingAs($this->petugas)->get(route('kitchen.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('kitchen.permintaan.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-dapur', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('kitchen.index'))->assertForbidden();
    }

    #[Test]
    public function barang_bisa_ditambah_lewat_http(): void
    {
        $this->actingAs($this->petugas)->post(route('kitchen.barang.simpan'), [
            'code' => 'DPR002',
            'name' => 'Gula Pasir',
            'category_id' => ItemCategory::query()->first()->id,
            'unit_of_measure' => 'kg',
        ])->assertRedirect();

        $this->assertDatabaseHas('kitchen.items', ['code' => 'DPR002']);
    }
}
