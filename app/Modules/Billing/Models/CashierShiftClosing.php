<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penutupan satu shift kasir.
 *
 * `closing_kasir` Khanza berisi tepat tiga kolom — shift, jam masuk, jam
 * pulang — jadi ia jadwal shift, bukan penutupan. Tidak ada hitungan uang
 * laci dan tidak ada selisih, sehingga tidak ada apa pun yang
 * membandingkan uang di tangan kasir dengan yang tercatat sistem.
 */
class CashierShiftClosing extends Model
{
    protected $table = 'billing.cashier_shift_closings';

    protected $guarded = ['id'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    /**
     * Selisih laci: dihitung, tidak pernah disimpan.
     *
     * Positif berarti uang LEBIH dari yang tercatat; negatif berarti kurang.
     * Selisih yang dibekukan jadi kolom akan salah begitu salah satu
     * sisinya dikoreksi — dan selisih kas yang salah adalah persis angka
     * yang dipakai menuduh orang.
     */
    public function selisih(): string
    {
        return bcsub((string) $this->counted_cash, (string) $this->recorded_cash, 2);
    }

    public function cocok(): bool
    {
        return bccomp((string) $this->counted_cash, (string) $this->recorded_cash, 2) === 0;
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'window_from' => 'datetime',
            'window_until' => 'datetime',
            'closed_at' => 'datetime',
            'counted_cash' => 'decimal:2',
            'recorded_cash' => 'decimal:2',
            'recorded_noncash' => 'decimal:2',
        ];
    }
}
