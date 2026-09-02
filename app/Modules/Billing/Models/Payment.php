<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'billing.payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'tunai' => 'Tunai',
            'debit' => 'Kartu Debit',
            'kredit' => 'Kartu Kredit',
            'qris' => 'QRIS',
            'transfer' => 'Transfer Bank',
            default => $method,
        };
    }
}
