<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** penerimaan_non_medis. */
class GoodsReceipt extends Model
{
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_TERVERIFIKASI = 'terverifikasi';

    protected $table = 'inventory.goods_receipts';

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
