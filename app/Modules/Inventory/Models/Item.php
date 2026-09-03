<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    protected $table = 'inventory.items';

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
