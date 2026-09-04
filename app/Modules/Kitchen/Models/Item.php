<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** dapur_barang. */
class Item extends Model
{
    protected $table = 'kitchen.items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->reorder_point !== null && (float) $this->quantity_on_hand < $this->reorder_point;
    }
}
