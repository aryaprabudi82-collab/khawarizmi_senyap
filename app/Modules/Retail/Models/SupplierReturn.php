<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retur ke suplier. Alasannya WAJIB: tanpa alasan, retur tidak bisa
 * dibedakan dari barang yang hilang lalu dicatat sebagai retur.
 */
class SupplierReturn extends Model
{
    protected $table = 'retail.supplier_returns';

    protected $guarded = ['id'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'receipt_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class, 'return_id');
    }

    protected function casts(): array
    {
        return ['returned_on' => 'date', 'total_amount' => 'decimal:2'];
    }
}
