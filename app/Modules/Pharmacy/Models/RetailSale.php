<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** penjualan_obat + piutang_obat — dibedakan payment_status, bukan tabel terpisah. */
class RetailSale extends Model
{
    public const PAYMENT_LUNAS = 'lunas';
    public const PAYMENT_PIUTANG = 'piutang';

    public const STATUS_SELESAI = 'selesai';
    public const STATUS_RETUR_SEBAGIAN = 'retur-sebagian';
    public const STATUS_RETUR_PENUH = 'retur-penuh';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'pharmacy.retail_sales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RetailSaleItem::class, 'sale_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(RetailSaleReturn::class, 'sale_id');
    }
}
