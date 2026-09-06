<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu penerimaan atau pengeluaran kas di luar tagihan pasien.
 *
 * amount SELALU positif; arahnya dibaca dari direction. Lihat catatan
 * migrasinya untuk alasannya.
 */
class CashTransaction extends Model
{
    protected $table = 'finance.cash_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashCategory::class, 'category_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
