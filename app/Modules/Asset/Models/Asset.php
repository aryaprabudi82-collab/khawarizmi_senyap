<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    public const CONDITION_BAIK = 'baik';
    public const CONDITION_RUSAK_RINGAN = 'rusak-ringan';
    public const CONDITION_RUSAK_BERAT = 'rusak-berat';

    public const STATUS_AKTIF = 'aktif';
    public const STATUS_DALAM_PERBAIKAN = 'dalam-perbaikan';
    public const STATUS_DIHAPUSKAN = 'dihapuskan';

    protected $table = 'asset.assets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'type_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(AssetManufacturer::class, 'manufacturer_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class);
    }
}
