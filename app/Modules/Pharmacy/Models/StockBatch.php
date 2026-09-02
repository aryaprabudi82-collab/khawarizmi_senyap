<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatch extends Model
{
    protected $table = 'pharmacy.stock_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity_on_hand' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    /**
     * Urutan pengambilan FEFO: yang paling dekat kedaluwarsa keluar lebih dulu.
     * Batch tanpa tanggal kedaluwarsa diletakkan paling belakang.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('expiry_date IS NULL, expiry_date ASC, id ASC');
    }

    public function scopeUsable(Builder $query, ?string $on = null): Builder
    {
        $on ??= now()->toDateString();

        return $query->where('quantity_on_hand', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $on));
    }

    public function isExpired(?string $on = null): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->lt($on ?? now()->startOfDay());
    }
}
