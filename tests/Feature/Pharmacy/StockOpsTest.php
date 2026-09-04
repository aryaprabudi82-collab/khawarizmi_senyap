<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\StockLedger;
use App\Modules\Pharmacy\Services\StockOpnameService;
use App\Modules\Pharmacy\Services\StockReportService;
use App\Modules\Pharmacy\Services\StockTransferService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockOpsTest extends TestCase
{
    use RefreshDatabase;

    private StockLedger $ledger;
    private StockOpnameService $opnames;
    private StockTransferService $transfers;
    private StockReportService $reports;

    private User $apoteker;
    private Drug $obat;
    private StockLocation $gudang;
    private StockLocation $depo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, PharmacySeeder::class]);

        $this->ledger = app(StockLedger::class);
        $this->opnames = app(StockOpnameService::class);
        $this->transfers = app(StockTransferService::class);
        $this->reports = app(StockReportService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-stok-ops', 'name' => 'Apoteker Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
        $this->gudang = StockLocation::query()->where('code', 'GUDANG')->firstOrFail();
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();

        $this->ledger->receive($this->obat->id, $this->gudang->id, 'BATCH-OPS-1', 100, now()->addYear()->toDateString(), 1500, $this->apoteker);
    }

    #[Test]
    public function opname_menyesuaikan_stok_lewat_koreksi_saat_selisih_kurang(): void
    {
        $opname = $this->opnames->start($this->gudang->id, 'Opname rutin', $this->apoteker->id);
        $item = $opname->items->firstWhere('batch_id', StockBatch::where('batch_number', 'BATCH-OPS-1')->value('id'));

        $this->opnames->recordCount($opname, $item->id, 90);
        $selesai = $this->opnames->complete($opname, $this->apoteker);

        $batch = StockBatch::query()->where('batch_number', 'BATCH-OPS-1')->firstOrFail();
        $this->assertEqualsWithDelta(90.0, (float) $batch->quantity_on_hand, 0.01);
        $this->assertSame('selesai', $selesai->status);

        $gerak = DB::table('pharmacy.stock_movements')->where('batch_id', $batch->id)->where('kind', 'koreksi')->first();
        $this->assertNotNull($gerak);
        $this->assertEqualsWithDelta(-10.0, (float) $gerak->quantity, 0.01);
    }

    #[Test]
    public function opname_yang_sudah_selesai_tidak_bisa_diselesaikan_ulang(): void
    {
        $opname = $this->opnames->start($this->gudang->id, null, $this->apoteker->id);
        $this->opnames->complete($opname, $this->apoteker);

        $this->expectException(PharmacyException::class);
        $this->opnames->complete($opname, $this->apoteker);
    }

    #[Test]
    public function mutasi_memindahkan_stok_dari_gudang_ke_depo(): void
    {
        $batch = StockBatch::query()->where('batch_number', 'BATCH-OPS-1')->firstOrFail();

        $mutasi = $this->transfers->transfer(
            $this->gudang->id, $this->depo->id,
            [['batch_id' => $batch->id, 'quantity' => 30]],
            'Kebutuhan resep harian', $this->apoteker,
        );

        $sisaGudang = StockBatch::query()->where('batch_number', 'BATCH-OPS-1')->where('location_id', $this->gudang->id)->firstOrFail();
        $diDepo = StockBatch::query()->where('batch_number', 'BATCH-OPS-1')->where('location_id', $this->depo->id)->first();

        $this->assertEqualsWithDelta(70.0, (float) $sisaGudang->quantity_on_hand, 0.01);
        $this->assertNotNull($diDepo);
        $this->assertEqualsWithDelta(30.0, (float) $diDepo->quantity_on_hand, 0.01);
        $this->assertSame($mutasi->from_location_id, $this->gudang->id);
    }

    #[Test]
    public function mutasi_melebihi_stok_asal_ditolak(): void
    {
        $batch = StockBatch::query()->where('batch_number', 'BATCH-OPS-1')->firstOrFail();

        $this->expectException(PharmacyException::class);
        $this->transfers->transfer($this->gudang->id, $this->depo->id, [['batch_id' => $batch->id, 'quantity' => 999]], null, $this->apoteker);
    }

    #[Test]
    public function laporan_stok_menghitung_sisa_dan_darurat_stok(): void
    {
        // PharmacySeeder sudah menaruh stok lain untuk obat ini di lokasi lain
        // (2 batch per item) — minimum harus melebihi total gabungan semua
        // lokasi supaya benar-benar tercatat "darurat", lowStock() menjumlah
        // lintas lokasi by design.
        $this->obat->update(['minimum_stock' => 100000]);

        $sisa = $this->reports->currentStock($this->gudang->id);
        $darurat = $this->reports->lowStock();

        $baris = $sisa->firstWhere('drug_id', $this->obat->id);
        $this->assertNotNull($baris);
        $this->assertEqualsWithDelta(100.0, (float) $baris->total, 0.01);

        $barisDarurat = $darurat->firstWhere('drug_id', $this->obat->id);
        $this->assertNotNull($barisDarurat);
    }

    #[Test]
    public function layar_opname_mutasi_dan_laporan_stok_hanya_untuk_apoteker(): void
    {
        $this->actingAs($this->apoteker)->get(route('pharmacy.opname.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.mutasi.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.laporan-stok.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-stok-ops', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('pharmacy.opname.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.mutasi.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.laporan-stok.index'))->assertForbidden();
    }

    #[Test]
    public function form_mutasi_bisa_disubmit_lewat_http(): void
    {
        $batch = StockBatch::query()->where('batch_number', 'BATCH-OPS-1')->firstOrFail();

        $this->actingAs($this->apoteker)->post(route('pharmacy.mutasi.simpan'), [
            'from_location_id' => $this->gudang->id,
            'to_location_id' => $this->depo->id,
            'batch_id' => [$batch->id],
            'quantity' => [20],
        ])->assertRedirect();

        $this->assertDatabaseHas('pharmacy.stock_transfers', [
            'from_location_id' => $this->gudang->id, 'to_location_id' => $this->depo->id,
        ]);
    }
}
