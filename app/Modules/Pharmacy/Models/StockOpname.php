<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** stok_opname_obat (DlgInputStok). */
class StockOpname extends Model
{
    public const STATUS_DRAF = 'draf';
    public const STATUS_SELESAI = 'selesai';

    protected $table = 'pharmacy.stock_opnames';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id');
    }
}
