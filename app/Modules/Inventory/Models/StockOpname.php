<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** stok_opname_logistik (Stok Opname Non Medis). */
class StockOpname extends Model
{
    public const STATUS_DRAF = 'draf';
    public const STATUS_SELESAI = 'selesai';

    protected $table = 'inventory.stock_opnames';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id');
    }
}
