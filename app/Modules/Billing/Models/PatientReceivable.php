<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Piutang pasien: sisa tagihan yang resmi dijadikan utang dengan jatuh
 * tempo. Sisa dan status pelunasannya TIDAK disimpan di sini — keduanya
 * diturunkan dari tagihannya, supaya uang hanya punya satu sumber
 * kebenaran (lihat catatan migrasi).
 */
class PatientReceivable extends Model
{
    protected $table = 'billing.patient_receivables';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Sisa utang = sisa tagihannya, bukan angka tersendiri. */
    public function outstanding(): float
    {
        return $this->invoice->outstanding();
    }

    public function isSettled(): bool
    {
        return $this->outstanding() <= 0;
    }

    /** Lewat jatuh tempo dan masih ada sisa. */
    public function isOverdue(): bool
    {
        return ! $this->isCancelled()
            && ! $this->isSettled()
            && $this->due_date->isPast()
            && ! $this->due_date->isToday();
    }

    public function scopeBerlaku(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }
}
