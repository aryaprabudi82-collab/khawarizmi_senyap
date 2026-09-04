<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\CompoundingMethod;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugCategory;
use App\Modules\Pharmacy\Models\DrugClass;
use App\Modules\Pharmacy\Models\Manufacturer;
use App\Modules\Pharmacy\Models\MeasureUnit;
use App\Modules\Pharmacy\Models\Supplier;
use App\Modules\Pharmacy\Services\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController
{
    public function __construct(private readonly MasterDataService $master) {}

    public function index(): View
    {
        return view('pharmacy::master.index', [
            'obat' => Drug::query()->with(['drugCategory', 'drugClass', 'manufacturer', 'units.unit'])->orderBy('name')->get(),
            'kategori' => DrugCategory::query()->orderBy('name')->get(),
            'golongan' => DrugClass::query()->orderBy('name')->get(),
            'satuan' => MeasureUnit::query()->orderBy('name')->get(),
            'suplier' => Supplier::query()->orderBy('name')->get(),
            'industri' => Manufacturer::query()->orderBy('name')->get(),
            'metodeRacik' => CompoundingMethod::query()->orderBy('name')->get(),
        ]);
    }

    public function storeDrug(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique(Drug::class, 'code')],
            'name' => ['required', 'string', 'max:200'],
            'generic_name' => ['nullable', 'string', 'max:200'],
            'category' => ['required', 'in:obat,bhp,alkes'],
            'drug_category_id' => ['nullable', 'integer', Rule::exists(DrugCategory::class, 'id')],
            'drug_class_id' => ['nullable', 'integer', Rule::exists(DrugClass::class, 'id')],
            'manufacturer_id' => ['nullable', 'integer', Rule::exists(Manufacturer::class, 'id')],
            'form' => ['nullable', 'string', 'max:40'],
            'strength' => ['nullable', 'string', 'max:40'],
            'unit' => ['required', 'string', 'max:20'],
            'requires_prescription' => ['nullable', 'boolean'],
            'is_narcotic' => ['nullable', 'boolean'],
            'is_psychotropic' => ['nullable', 'boolean'],
            'is_high_alert' => ['nullable', 'boolean'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'code' => 'kode', 'name' => 'nama', 'category' => 'jenis', 'drug_category_id' => 'kategori',
            'drug_class_id' => 'golongan', 'manufacturer_id' => 'industri farmasi', 'unit' => 'satuan dasar',
            'sell_price' => 'harga jual', 'vat_rate' => 'tarif PPN', 'minimum_stock' => 'stok minimum',
        ]);

        foreach (['requires_prescription', 'is_narcotic', 'is_psychotropic', 'is_high_alert'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['is_active'] = true;

        $this->master->createDrug($data);

        return back()->with('sukses', "{$data['name']} ditambahkan ke katalog obat/BHP/alkes.");
    }

    public function storeDrugCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(DrugCategory::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->master->createDrugCategory($data + ['is_active' => true]);

        return back()->with('sukses', "Kategori {$data['name']} ditambahkan.");
    }

    public function storeDrugClass(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(DrugClass::class, 'code')],
            'name' => ['required', 'string', 'max:60'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->master->createDrugClass($data + ['is_active' => true]);

        return back()->with('sukses', "Golongan {$data['name']} ditambahkan.");
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(MeasureUnit::class, 'code')],
            'name' => ['required', 'string', 'max:40'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->master->createUnit($data);

        return back()->with('sukses', "Satuan {$data['name']} ditambahkan.");
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Supplier::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'license_number' => ['nullable', 'string', 'max:60'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'license_number' => 'no. izin PBF']);

        $this->master->createSupplier($data + ['is_active' => true]);

        return back()->with('sukses', "Suplier {$data['name']} ditambahkan.");
    }

    public function storeManufacturer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Manufacturer::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['nullable', 'string', 'max:60'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'license_number' => 'no. izin industri']);

        $this->master->createManufacturer($data + ['is_active' => true]);

        return back()->with('sukses', "Industri farmasi {$data['name']} ditambahkan.");
    }

    public function storeCompoundingMethod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(CompoundingMethod::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->master->createCompoundingMethod($data + ['is_active' => true]);

        return back()->with('sukses', "Metode racik {$data['name']} ditambahkan.");
    }

    public function storeDrugUnit(Request $request, Drug $obat): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer', Rule::exists(MeasureUnit::class, 'id')],
            'conversion_to_base' => ['required', 'numeric', 'min:0.0001'],
            'is_purchase_unit' => ['nullable', 'boolean'],
            'is_dispense_unit' => ['nullable', 'boolean'],
        ], [], ['unit_id' => 'satuan', 'conversion_to_base' => 'nilai konversi']);

        $data['is_purchase_unit'] = $request->boolean('is_purchase_unit');
        $data['is_dispense_unit'] = $request->boolean('is_dispense_unit');

        $this->master->addDrugUnit($obat, $data);

        return back()->with('sukses', "Konversi satuan untuk {$obat->name} ditambahkan.");
    }
}
