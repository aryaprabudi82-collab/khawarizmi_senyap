<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris tagihan. Hanya menerima INSERT — ditarik dari peristiwa di konteks
 * lain lewat proses sinkronisasi yang idempoten.
 */
class ChargeLine extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'billing.charge_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'charged_at' => 'datetime',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
