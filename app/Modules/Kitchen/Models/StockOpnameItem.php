<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    protected $table = 'kitchen.stock_opname_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:2',
            'counted_quantity' => 'decimal:2',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'opname_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Positif = stok fisik lebih banyak dari sistem, negatif = lebih sedikit (susut/hilang). */
    public function difference(): ?float
    {
        if ($this->counted_quantity === null) {
            return null;
        }

        return (float) $this->counted_quantity - (float) $this->system_quantity;
    }
}
