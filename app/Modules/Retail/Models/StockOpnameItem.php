<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hitung fisik.
 *
 * Yang disimpan cuma dua angka yang benar-benar dicatat orang: stok
 * menurut buku besar saat dihitung, dan hasil hitung fisiknya. Selisih
 * dan nilai rupiahnya DIHITUNG — `tokoopname.selisih` dan `nomihilang`
 * Khanza adalah nilai turunan yang dibekukan, dan nilai turunan yang
 * dibekukan akan salah begitu hitungan fisiknya dikoreksi.
 */
class StockOpnameItem extends Model
{
    protected $table = 'retail.stock_opname_items';

    protected $guarded = ['id'];

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Positif berarti fisik lebih banyak daripada catatan. */
    public function selisih(): ?int
    {
        return $this->counted_quantity === null
            ? null
            : (int) $this->counted_quantity - (int) $this->system_quantity;
    }

    public function nilaiSelisih(): ?float
    {
        $selisih = $this->selisih();

        return $selisih === null ? null : round($selisih * (float) $this->unit_cost, 2);
    }

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }
}
