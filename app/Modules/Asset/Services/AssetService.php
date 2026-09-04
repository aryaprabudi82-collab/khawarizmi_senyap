<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetManufacturer;
use App\Modules\Asset\Models\AssetType;

class AssetService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function createAsset(array $data): Asset
    {
        return Asset::query()->create($data + [
            'asset_number' => $this->numbers->allocate('AST'),
            'condition' => $data['condition'] ?? Asset::CONDITION_BAIK,
            'status' => Asset::STATUS_AKTIF,
            'is_active' => true,
        ]);
    }

    public function updateAsset(Asset $asset, array $data): Asset
    {
        $asset->update($data);

        return $asset->refresh();
    }

    public function createCategory(array $data): AssetCategory
    {
        return AssetCategory::query()->create($data + ['is_active' => true]);
    }

    public function createLocation(array $data): AssetLocation
    {
        return AssetLocation::query()->create($data + ['is_active' => true]);
    }

    public function createType(array $data): AssetType
    {
        return AssetType::query()->create($data + ['is_active' => true]);
    }

    public function createManufacturer(array $data): AssetManufacturer
    {
        return AssetManufacturer::query()->create($data + ['is_active' => true]);
    }
}
