<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    public const STATUS_BERJALAN = 'berjalan';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'retail.stock_opnames';

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id');
    }

    /**
     * Baris yang belum dihitung fisik. DIHITUNG dari kolom yang kosong —
     * opname yang ditutup dengan baris belum terhitung berarti stok
     * barang itu dikoreksi ke angka yang tidak pernah dihitung siapa pun.
     *
     * @return list<string>
     */
    public function belumDihitung(): array
    {
        return $this->items
            ->filter(fn (StockOpnameItem $b) => $b->counted_quantity === null)
            ->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return ['counted_on' => 'date', 'completed_at' => 'datetime'];
    }
}
