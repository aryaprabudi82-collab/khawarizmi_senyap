<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** dapur_opname. */
class StockOpname extends Model
{
    public const STATUS_DRAF = 'draf';
    public const STATUS_SELESAI = 'selesai';

    protected $table = 'kitchen.stock_opnames';

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
