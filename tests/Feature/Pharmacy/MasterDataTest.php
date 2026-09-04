<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugCategory;
use App\Modules\Pharmacy\Models\DrugClass;
use App\Modules\Pharmacy\Models\Manufacturer;
use App\Modules\Pharmacy\Models\MeasureUnit;
use App\Modules\Pharmacy\Services\MasterDataService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private MasterDataService $master;
    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->master = app(MasterDataService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-master-farmasi', 'name' => 'Apoteker Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());
    }

    #[Test]
    public function obat_baru_tercatat_dengan_kategori_golongan_dan_industri(): void
    {
        $kategori = $this->master->createDrugCategory(['code' => 'ANTB', 'name' => 'Antibiotik', 'is_active' => true]);
        $golongan = $this->master->createDrugClass(['code' => 'KERAS', 'name' => 'Obat Keras', 'is_active' => true]);
        $industri = $this->master->createManufacturer(['code' => 'KF', 'name' => 'Kimia Farma', 'is_active' => true]);

        $obat = $this->master->createDrug([
            'code' => 'OBT-100', 'name' => 'Siprofloksasin 500 mg', 'category' => 'obat',
            'drug_category_id' => $kategori->id, 'drug_class_id' => $golongan->id, 'manufacturer_id' => $industri->id,
            'unit' => 'tablet', 'sell_price' => 2500, 'is_active' => true,
        ]);

        $this->assertSame('Antibiotik', $obat->drugCategory->name);
        $this->assertSame('Obat Keras', $obat->drugClass->name);
        $this->assertSame('Kimia Farma', $obat->manufacturer->name);
    }

    #[Test]
    public function konversi_satuan_menyimpan_rasio_relatif_terhadap_satuan_dasar(): void
    {
        $obat = $this->master->createDrug([
            'code' => 'OBT-101', 'name' => 'Parasetamol 500 mg', 'category' => 'obat',
            'unit' => 'tablet', 'sell_price' => 500, 'is_active' => true,
        ]);
        $boks = $this->master->createUnit(['code' => 'BOKS', 'name' => 'Boks']);
        $strip = $this->master->createUnit(['code' => 'STRIP', 'name' => 'Strip']);

        $this->master->addDrugUnit($obat, ['unit_id' => $strip->id, 'conversion_to_base' => 10, 'is_dispense_unit' => true]);
        $this->master->addDrugUnit($obat, ['unit_id' => $boks->id, 'conversion_to_base' => 100, 'is_purchase_unit' => true]);

        $obat->refresh()->load('units.unit');

        $this->assertCount(2, $obat->units);
        $stripUnit = $obat->units->firstWhere('unit_id', $strip->id);
        $this->assertEqualsWithDelta(10.0, (float) $stripUnit->conversion_to_base, 0.0001);
        $this->assertTrue((bool) $stripUnit->is_dispense_unit);
        $this->assertFalse((bool) $stripUnit->is_purchase_unit);
    }

    #[Test]
    public function satuan_yang_sama_tidak_bisa_dipasang_dua_kali_ke_obat_yang_sama(): void
    {
        $obat = $this->master->createDrug([
            'code' => 'OBT-102', 'name' => 'Amoksisilin 500 mg', 'category' => 'obat',
            'unit' => 'kapsul', 'sell_price' => 1500, 'is_active' => true,
        ]);
        $strip = $this->master->createUnit(['code' => 'STRIP2', 'name' => 'Strip']);

        $this->master->addDrugUnit($obat, ['unit_id' => $strip->id, 'conversion_to_base' => 10]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->master->addDrugUnit($obat, ['unit_id' => $strip->id, 'conversion_to_base' => 12]);
    }

    #[Test]
    public function layar_master_data_farmasi_hanya_untuk_apoteker(): void
    {
        $this->actingAs($this->apoteker)->get(route('pharmacy.master.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-master-farmasi', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('pharmacy.master.index'))->assertForbidden();
    }

    #[Test]
    public function form_tambah_obat_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->apoteker)->post(route('pharmacy.master.obat.simpan'), [
            'code' => 'OBT-200', 'name' => 'Loratadin 10 mg', 'category' => 'obat',
            'unit' => 'tablet', 'sell_price' => 1200,
        ])->assertRedirect();

        $this->assertDatabaseHas('pharmacy.drugs', ['code' => 'OBT-200', 'name' => 'Loratadin 10 mg']);
    }

    #[Test]
    public function form_konversi_satuan_bisa_disubmit_lewat_http(): void
    {
        $obat = $this->master->createDrug([
            'code' => 'OBT-201', 'name' => 'Ranitidin 150 mg', 'category' => 'obat',
            'unit' => 'tablet', 'sell_price' => 900, 'is_active' => true,
        ]);
        $satuan = $this->master->createUnit(['code' => 'BOKS2', 'name' => 'Boks']);

        $this->actingAs($this->apoteker)->post(route('pharmacy.master.konversi.simpan', $obat), [
            'unit_id' => $satuan->id, 'conversion_to_base' => 50, 'is_purchase_unit' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('pharmacy.drug_units', [
            'drug_id' => $obat->id, 'unit_id' => $satuan->id, 'is_purchase_unit' => true,
        ]);
    }
}
