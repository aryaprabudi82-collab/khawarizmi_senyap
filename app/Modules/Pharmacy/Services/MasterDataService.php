<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\CompoundingMethod;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugCategory;
use App\Modules\Pharmacy\Models\DrugClass;
use App\Modules\Pharmacy\Models\DrugUnit;
use App\Modules\Pharmacy\Models\Manufacturer;
use App\Modules\Pharmacy\Models\MeasureUnit;
use App\Modules\Pharmacy\Models\Supplier;

/**
 * Domain D item 1 (master data) — obat, kategori/golongan obat, satuan,
 * suplier, industri farmasi, metode racik. jenis_barang tidak di sini,
 * lihat catatan migrasi (sudah terpenuhi drugs.category).
 */
class MasterDataService
{
    public function createDrug(array $data): Drug
    {
        return Drug::query()->create($data);
    }

    public function updateDrug(Drug $drug, array $data): Drug
    {
        $drug->update($data);

        return $drug->refresh();
    }

    public function createDrugCategory(array $data): DrugCategory
    {
        return DrugCategory::query()->create($data);
    }

    public function createDrugClass(array $data): DrugClass
    {
        return DrugClass::query()->create($data);
    }

    public function createUnit(array $data): MeasureUnit
    {
        return MeasureUnit::query()->create($data);
    }

    public function createSupplier(array $data): Supplier
    {
        return Supplier::query()->create($data);
    }

    public function createManufacturer(array $data): Manufacturer
    {
        return Manufacturer::query()->create($data);
    }

    public function createCompoundingMethod(array $data): CompoundingMethod
    {
        return CompoundingMethod::query()->create($data);
    }

    /** konversi_satuan — satu obat boleh punya beberapa satuan tambahan, masing-masing baris drug_units sendiri (bukan sync/replace-all). */
    public function addDrugUnit(Drug $drug, array $data): DrugUnit
    {
        return $drug->units()->create($data);
    }
}
