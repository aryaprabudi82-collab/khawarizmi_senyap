<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Barang dagangan toko.
 *
 * TIDAK punya kolom stok. `tokobarang.stok` Khanza adalah saldo berjalan
 * yang menempel pada barangnya; saldo tanpa buku besar tidak bisa
 * direkonsiliasi, dan begitu satu transaksi gagal di tengah, angkanya
 * melenceng tanpa cara menelusuri sejak kapan maupun karena apa.
 */
class Product extends Model
{
    protected $table = 'retail.products';

    protected $guarded = ['id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    /** Stok DIHITUNG dari buku besar, tidak pernah dibaca dari kolom. */
    public function stok(): int
    {
        return (int) $this->movements()->sum('quantity');
    }

    public function dibawahMinimum(): bool
    {
        return $this->stok() < (int) $this->minimum_stock;
    }

    protected function casts(): array
    {
        return ['base_cost' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
