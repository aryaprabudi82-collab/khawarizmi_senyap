<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retur penjualan.
 *
 * `refunded_in_cash` false untuk retur atas penjualan PIUTANG: yang
 * berkurang tagihannya, bukan kasnya. Menyamakannya dengan retur tunai
 * membuat kas tercatat keluar untuk uang yang belum pernah masuk.
 */
class SaleReturn extends Model
{
    protected $table = 'retail.sale_returns';

    protected $guarded = ['id'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'return_id');
    }

    protected function casts(): array
    {
        return [
            'returned_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'refunded_in_cash' => 'boolean',
        ];
    }
}
