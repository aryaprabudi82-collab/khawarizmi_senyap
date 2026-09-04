<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetManufacturer;
use App\Modules\Asset\Models\AssetType;
use App\Modules\Asset\Services\AssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(): View
    {
        return view('asset::master.index', [
            'aset' => Asset::query()->with(['category', 'location', 'type', 'manufacturer'])->orderBy('name')->get(),
            'kategori' => AssetCategory::query()->orderBy('name')->get(),
            'lokasi' => AssetLocation::query()->orderBy('name')->get(),
            'jenis' => AssetType::query()->orderBy('name')->get(),
            'produsen' => AssetManufacturer::query()->orderBy('name')->get(),
        ]);
    }

    public function storeAsset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'integer'],
            'manufacturer_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:100'],
            'acquisition_date' => ['nullable', 'date'],
            'acquisition_value' => ['nullable', 'numeric', 'min:0'],
        ], [], ['name' => 'nama', 'category_id' => 'kategori', 'location_id' => 'lokasi', 'type_id' => 'jenis', 'manufacturer_id' => 'produsen', 'acquisition_date' => 'tanggal perolehan', 'acquisition_value' => 'nilai perolehan']);

        $aset = $this->assets->createAsset($data);

        return back()->with('sukses', "Aset {$aset->asset_number} — {$aset->name} ditambahkan.");
    }

    public function updateAsset(Request $request, Asset $aset): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'integer'],
            'manufacturer_id' => ['nullable', 'integer'],
            'condition' => ['required', 'in:baik,rusak-ringan,rusak-berat'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'category_id' => 'kategori', 'location_id' => 'lokasi', 'type_id' => 'jenis', 'manufacturer_id' => 'produsen', 'condition' => 'kondisi']);

        $data['is_active'] = $request->boolean('is_active');

        $this->assets->updateAsset($aset, $data);

        return back()->with('sukses', "Aset {$aset->name} diperbarui.");
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(AssetCategory::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->assets->createCategory($data);

        return back()->with('sukses', "Kategori {$data['name']} ditambahkan.");
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(AssetLocation::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->assets->createLocation($data);

        return back()->with('sukses', "Lokasi {$data['name']} ditambahkan.");
    }

    /** inventaris_jenis. */
    public function storeType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(AssetType::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->assets->createType($data);

        return back()->with('sukses', "Jenis {$data['name']} ditambahkan.");
    }

    /** inventaris_produsen. */
    public function storeManufacturer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(AssetManufacturer::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->assets->createManufacturer($data);

        return back()->with('sukses', "Produsen {$data['name']} ditambahkan.");
    }
}
