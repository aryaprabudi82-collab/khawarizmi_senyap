<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualAdjustment extends Model
{
    public const KIND_TAMBAHAN = 'tambahan';
    public const KIND_POTONGAN = 'potongan';

    protected $table = 'billing.manual_adjustments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    public function scopeBerlaku(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
