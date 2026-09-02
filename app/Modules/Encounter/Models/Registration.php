<?php

namespace App\Modules\Encounter\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    public const STATUS_TERDAFTAR = 'terdaftar';
    public const STATUS_DIPANGGIL = 'dipanggil';
    public const STATUS_DILAYANI = 'dilayani';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_BATAL = 'batal';
    public const STATUS_TIDAK_HADIR = 'tidak-hadir';

    protected $table = 'encounter.registrations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'registered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'queue_number' => 'integer',
            'registration_fee' => 'decimal:2',
        ];
    }

    /** Antrean satu unit pada satu tanggal, urut nomor. */
    public function scopeQueueFor(Builder $query, \DateTimeInterface $date, int $unitId): Builder
    {
        return $query->whereDate('service_date', $date->format('Y-m-d'))
            ->where('unit_id', $unitId)
            ->where('status', '<>', self::STATUS_BATAL)
            ->orderBy('queue_number');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_BATAL;
    }
}
