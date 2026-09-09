<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga jual per tingkat, sebagai BARIS.
 *
 * Khanza menetapkan tepat tiga tingkat sebagai kolom pada barangnya.
 * Koperasi rumah sakit hampir selalu punya tingkat keempat — harga
 * karyawan — dan tiga kolom tetap tidak bisa menyatakannya tanpa migrasi;
 * lebih buruk lagi, kolom keempat yang ditambahkan belakangan akan kosong
 * pada seluruh barang lama dan terbaca sebagai "gratis".
 */
class ProductPrice extends Model
{
    protected $table = 'retail.product_prices';

    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'price_tier_id');
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
