<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    public const BAYAR_BELUM = 'belum';

    public const BAYAR_SEBAGIAN = 'sebagian';

    public const BAYAR_LUNAS = 'lunas';

    protected $table = 'retail.goods_receipts';

    protected $guarded = ['id'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'receipt_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'receipt_id');
    }

    /**
     * Sisa hutang. DIHITUNG — saldo hutang yang disimpan sebagai kolom
     * tersendiri akan melenceng begitu satu pembayaran gagal di tengah,
     * dan yang tertinggal cuma angka yang tidak bisa ditelusuri ke nota
     * mana pun.
     */
    public function sisaHutang(): float
    {
        return round((float) $this->total_amount - (float) $this->payments()->sum('amount'), 2);
    }

    public function terlambatBayar(): bool
    {
        return $this->payment_status !== self::BAYAR_LUNAS
            && $this->due_on !== null
            && $this->due_on->isBefore(now()->startOfDay());
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'due_on' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }
}
