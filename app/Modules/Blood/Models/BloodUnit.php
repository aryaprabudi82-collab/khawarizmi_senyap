<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodUnit extends Model
{
    public const STATUS_KARANTINA = 'karantina';
    public const STATUS_TERSEDIA = 'tersedia';
    public const STATUS_DITAHAN = 'ditahan';
    public const STATUS_DIKELUARKAN = 'dikeluarkan';
    public const STATUS_KEDALUWARSA = 'kedaluwarsa';
    public const STATUS_DITOLAK = 'ditolak';

    protected $table = 'blood.blood_units';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'expiry_date' => 'date',
        ];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->isPast();
    }

    public function scopeTersedia(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TERSEDIA);
    }
}
