<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pesanan ke suplier.
 *
 * `toko_surat_pemesanan` Khanza punya tabelnya sendiri berikut detailnya,
 * terpisah dari `tokopembelian`. Dua tabel untuk satu pesanan berarti dua
 * tempat yang harus sepakat tentang barang dan jumlah yang sama — dan
 * begitu keduanya berbeda, tidak ada cara menentukan mana yang dikirim ke
 * suplier. Di sini surat pemesanan adalah TAMPILAN CETAK dari pesanan ini.
 */
class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DIKIRIM = 'dikirim';

    public const STATUS_SEBAGIAN = 'sebagian';

    public const STATUS_DITERIMA = 'diterima';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'retail.purchase_orders';

    protected $guarded = ['id'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'order_id');
    }

    /**
     * Sisa yang belum diterima per produk. DIHITUNG dari selisih pesanan
     * dan seluruh penerimaan — kolom "sisa" yang disimpan akan melenceng
     * begitu satu penerimaan gagal di tengah.
     *
     * @return array<int, int>
     */
    public function sisaPerProduk(): array
    {
        $dipesan = $this->items->pluck('quantity', 'product_id')->map(fn ($n) => (int) $n)->all();

        $diterima = [];
        foreach ($this->receipts as $terima) {
            foreach ($terima->items as $baris) {
                $diterima[$baris->product_id] = ($diterima[$baris->product_id] ?? 0) + (int) $baris->quantity;
            }
        }

        $sisa = [];
        foreach ($dipesan as $produkId => $jumlah) {
            $sisa[$produkId] = $jumlah - ($diterima[$produkId] ?? 0);
        }

        return $sisa;
    }

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'expected_on' => 'date'];
    }
}
