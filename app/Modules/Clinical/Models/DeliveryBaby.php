<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu bayi dari satu persalinan.
 *
 * Baris ini yang tidak ada di Khanza: catatan_persalinan hanya
 * menyediakan satu kolom untuk tiap hal tentang bayi, sehingga bayi
 * kedua pada kelahiran kembar tidak bisa dicatat sama sekali.
 */
class DeliveryBaby extends Model
{
    protected $table = 'clinical.delivery_babies';

    protected $guarded = ['id'];

    public const HIDUP = 'hidup';

    public const LAHIR_MATI = 'lahir-mati';

    /** Berat lahir rendah menurut batas WHO. */
    public const BBLR_GRAM = 2500;

    protected function casts(): array
    {
        return [
            'born_at' => 'datetime',
            'length_cm' => 'decimal:1',
            'head_circumference_cm' => 'decimal:1',
            'chest_circumference_cm' => 'decimal:1',
            'abdominal_circumference_cm' => 'decimal:1',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    public function apgarScores(): HasMany
    {
        return $this->hasMany(DeliveryBabyApgarScore::class, 'baby_id')->orderBy('minute');
    }

    public function isLiveBirth(): bool
    {
        return $this->birth_status === self::HIDUP;
    }

    /**
     * Berat lahir rendah.
     *
     * null saat beratnya belum tercatat — bukan false, karena "belum
     * ditimbang" bukan "beratnya cukup".
     */
    public function isLowBirthWeight(): ?bool
    {
        return $this->weight_grams === null ? null : $this->weight_grams < self::BBLR_GRAM;
    }
}
