<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** dapur_returbeli. */
class SupplierReturn extends Model
{
    public const STATUS_DIAJUKAN = 'diajukan';
    public const STATUS_SELESAI = 'selesai';

    protected $table = 'kitchen.supplier_returns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
    }
}
