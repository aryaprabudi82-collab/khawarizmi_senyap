<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** inventaris_sirkulasi. */
class AssetTransfer extends Model
{
    protected $table = 'asset.asset_transfers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'to_location_id');
    }
}
