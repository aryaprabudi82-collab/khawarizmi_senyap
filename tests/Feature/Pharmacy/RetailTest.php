<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\RetailSale;
use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\DonationReceiptService;
use App\Modules\Pharmacy\Services\PatientDrugReturnService;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\PharmacyRecapService;
use App\Modules\Pharmacy\Services\RetailSaleService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailTest extends TestCase
{
    use RefreshDatabase;

    private RetailSaleService $sales;
    private PatientDrugReturnService $patientReturns;
    private DonationReceiptService $donations;
    private PharmacyRecapService $recap;

    private User $apoteker;
    private Drug $obat;
    private StockLocation $depo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, PharmacySeeder::class]);

        $this->sales = app(RetailSaleService::class);
        $this->patientReturns = app(PatientDrugReturnService::class);
        $this->donations = app(DonationReceiptService::class);
        $this->recap = app(PharmacyRecapService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-retail', 'name' => 'Apoteker Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();
    }

    #[Test]
    public function penjualan_lunas_memotong_stok_dan_menyimpan_hpp_snapshot(): void
    {
        $stokAwal = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $penjualan = $this->sales->sell(
            ['customer_name' => 'Pembeli Uji', 'payment_status' => 'lunas'],
            [$this->obat->id => ['quantity' => 10, 'unit_price' => 2000]],
            $this->apoteker,
        );

        $stokAkhir = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $this->assertEqualsWithDelta(20000.0, (float) $penjualan->total_amount, 0.01);
        $this->assertEqualsWithDelta(20000.0, (float) $penjualan->paid_amount, 0.01);
        $this->assertEqualsWithDelta($stokAwal - 10, $stokAkhir, 0.01);

        $baris = $penjualan->items->first();
        $this->assertGreaterThan(0, (float) $baris->cost_price);
    }

    #[Test]
    public function penjualan_piutang_tidak_mencatat_paid_amount(): void
    {
        $penjualan = $this->sales->sell(
            ['customer_name' => 'Pembeli Kredit', 'payment_status' => 'piutang'],
            [$this->obat->id => ['quantity' => 5, 'unit_price' => 2000]],
            $this->apoteker,
        );

        $this->assertSame(RetailSale::PAYMENT_PIUTANG, $penjualan->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $penjualan->paid_amount, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $penjualan->total_amount, 0.01);
    }

    #[Test]
    public function retur_sebagian_mengembalikan_stok_dan_menandai_status(): void
    {
        $stokAwal = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $penjualan = $this->sales->sell(
            ['customer_name' => 'Pembeli Retur', 'payment_status' => 'lunas'],
            [$this->obat->id => ['quantity' => 10, 'unit_price' => 2000]],
            $this->apoteker,
        );

        $baris = $penjualan->items->first();
        $retur = $this->sales->returnItems($penjualan, [$baris->id => 4], 'Salah ambil obat', $this->apoteker);

        $stokAkhir = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $this->assertSame(RetailSale::STATUS_RETUR_SEBAGIAN, $penjualan->fresh()->status);
        $this->assertEqualsWithDelta($stokAwal - 10 + 4, $stokAkhir, 0.01);
        $this->assertCount(1, $retur->items);
    }

    #[Test]
    public function retur_melebihi_sisa_yang_dibeli_ditolak(): void
    {
        $penjualan = $this->sales->sell(
            ['customer_name' => 'Pembeli Retur 2', 'payment_status' => 'lunas'],
            [$this->obat->id => ['quantity' => 3, 'unit_price' => 2000]],
            $this->apoteker,
        );
        $baris = $penjualan->items->first();

        $this->expectException(PharmacyException::class);
        $this->sales->returnItems($penjualan, [$baris->id => 999], 'alasan', $this->apoteker);
    }

    #[Test]
    public function retur_obat_ranap_terikat_registrasi_dan_menambah_stok(): void
    {
        $registrasi = $this->daftarkan();
        $stokAwal = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $retur = $this->patientReturns->return($registrasi->id, [$this->obat->id => 6], 'Regimen diubah dokter', $this->apoteker);

        $stokAkhir = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $this->assertSame($registrasi->patient_mrn, $retur->patient_mrn);
        $this->assertEqualsWithDelta($stokAwal + 6, $stokAkhir, 0.01);
    }

    #[Test]
    public function hibah_menambah_stok_gudang_dengan_kind_hibah(): void
    {
        $gudang = StockLocation::query()->where('code', 'GUDANG')->firstOrFail();
        $donor = $this->donations->createDonor(['code' => 'DNR-01', 'name' => 'Yayasan Uji', 'is_active' => true]);

        $hibah = $this->donations->receive($donor->id, [
            $this->obat->id => ['quantity' => 25, 'batch_number' => 'HIBAH-UJI-1', 'expiry_date' => now()->addYear()->toDateString()],
        ], null, $this->apoteker);

        $batch = StockBatch::query()->where('location_id', $gudang->id)->where('batch_number', 'HIBAH-UJI-1')->firstOrFail();
        $gerak = \Illuminate\Support\Facades\DB::table('pharmacy.stock_movements')->where('batch_id', $batch->id)->first();

        $this->assertEqualsWithDelta(25.0, (float) $batch->quantity_on_hand, 0.01);
        $this->assertSame('hibah', $gerak->kind);
        $this->assertSame($donor->id, $hibah->donor_id);
    }

    #[Test]
    public function rekap_menghitung_omzet_untung_dan_piutang(): void
    {
        $this->sales->sell(['customer_name' => 'A', 'payment_status' => 'lunas'], [$this->obat->id => ['quantity' => 4, 'unit_price' => 2000]], $this->apoteker);
        $this->sales->sell(['customer_name' => 'B', 'payment_status' => 'piutang'], [$this->obat->id => ['quantity' => 2, 'unit_price' => 2000]], $this->apoteker);

        $ringkasan = $this->recap->penjualanRingkasan(now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $this->assertSame(2, $ringkasan['jumlah_transaksi']);
        $this->assertEqualsWithDelta(12000.0, $ringkasan['omzet'], 0.01);
        $this->assertEqualsWithDelta(4000.0, $ringkasan['piutang_sisa'], 0.01);
        $this->assertGreaterThan(0, $ringkasan['untung']);
    }

    #[Test]
    public function layar_penjualan_retur_ranap_hibah_dan_rekap_hanya_untuk_apoteker(): void
    {
        $this->actingAs($this->apoteker)->get(route('pharmacy.penjualan.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.retur-ranap.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.hibah.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.rekap.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-retail', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('pharmacy.penjualan.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.retur-ranap.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.hibah.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.rekap.index'))->assertForbidden();
    }

    #[Test]
    public function form_penjualan_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->apoteker)->post(route('pharmacy.penjualan.simpan'), [
            'customer_name' => 'Pembeli HTTP', 'payment_status' => 'lunas',
            'drug_id' => [$this->obat->id], 'quantity' => [2], 'unit_price' => [2000],
        ])->assertRedirect();

        $this->assertDatabaseHas('pharmacy.retail_sales', ['customer_name' => 'Pembeli HTTP']);
    }

    private function daftarkan(string $nama = 'Pasien Retail'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
