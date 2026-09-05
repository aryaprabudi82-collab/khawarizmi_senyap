<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    public const STATUS_TERBUKA = 'terbuka';
    public const STATUS_LUNAS = 'lunas';
    public const STATUS_DITANGGUNG_PENJAMIN = 'ditanggung-penjamin';
    public const STATUS_VOID = 'void';

    public const RESPONSIBILITY_PASIEN = 'pasien';
    public const RESPONSIBILITY_PENJAMIN = 'penjamin';

    protected $table = 'billing.invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function chargeLines(): HasMany
    {
        return $this->hasMany(ChargeLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_at');
    }

    public function manualAdjustments(): HasMany
    {
        return $this->hasMany(ManualAdjustment::class);
    }

    /** Piutang pasien yang masih berlaku atas tagihan ini, kalau ada. */
    public function patientReceivable(): HasOne
    {
        return $this->hasOne(PatientReceivable::class)->whereNull('cancelled_at');
    }

    /** Rawat inap dipisahkan Khanza sebagai kode kasir tersendiri (pembayaran_ranap). */
    public function isRanap(): bool
    {
        return $this->care_type === 'ranap';
    }

    public function outstanding(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }

    /** Tagihan ini ditagih langsung ke pasien, bukan ke penjamin. */
    public function isPatientPayable(): bool
    {
        return $this->payment_responsibility === self::RESPONSIBILITY_PASIEN;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_LUNAS, self::STATUS_DITANGGUNG_PENJAMIN], true);
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_TERBUKA => 'Terbuka',
            self::STATUS_LUNAS => 'Lunas',
            self::STATUS_DITANGGUNG_PENJAMIN => 'Ditanggung penjamin',
            self::STATUS_VOID => 'Dibatalkan',
            default => $status,
        };
    }
}
