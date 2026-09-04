<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** dapur_pemesanan (menu Khanza "Penerimaan Barang Dapur" — nama kode menyesatkan). */
class GoodsReceipt extends Model
{
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_TERVERIFIKASI = 'terverifikasi';

    protected $table = 'kitchen.goods_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_TERVERIFIKASI;
    }
}
