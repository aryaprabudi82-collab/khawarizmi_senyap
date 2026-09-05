<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloodUnit extends Model
{
    public const STATUS_KARANTINA = 'karantina';
    public const STATUS_TERSEDIA = 'tersedia';
    public const STATUS_DITAHAN = 'ditahan';
    public const STATUS_DIKELUARKAN = 'dikeluarkan';
    public const STATUS_KEDALUWARSA = 'kedaluwarsa';
    public const STATUS_DITOLAK = 'ditolak';
    /** utd_pemisahan_darah — unit whole-blood yang sudah dipisah jadi komponen, final seperti dikeluarkan/ditolak. */
    public const STATUS_DIPISAHKAN = 'dipisahkan';

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

    /** Unit whole-blood asal, terisi kalau unit ini hasil pemisahan komponen. */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    /** Unit komponen hasil pemisahan dari unit whole-blood ini. */
    public function childUnits(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
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
