<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ipsrs_pengadaan_barang — surat_pemesanan_non_medis adalah cetak dari data ini, lihat catatan migrasi. */
class PurchaseOrder extends Model
{
    public const STATUS_DRAF = 'draf';
    public const STATUS_DIPESAN = 'dipesan';
    public const STATUS_DITERIMA_SEBAGIAN = 'diterima-sebagian';
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'inventory.purchase_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
