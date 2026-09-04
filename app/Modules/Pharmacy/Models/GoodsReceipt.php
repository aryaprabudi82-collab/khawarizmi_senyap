<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** bayar_pemesanan_obat — terima barang sekaligus catat pembayaran, lihat catatan migrasi soal dua dialog Khanza yang digerbangi kode ini. */
class GoodsReceipt extends Model
{
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_TERVERIFIKASI = 'terverifikasi';

    public const PAYMENT_BELUM_BAYAR = 'belum-bayar';
    public const PAYMENT_LUNAS = 'lunas';

    protected $table = 'pharmacy.goods_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
            'paid_amount' => 'decimal:2',
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
