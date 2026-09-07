<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu KEJADIAN kecelakaan, bukan satu kunjungan.
 *
 * Korban yang sama kembali kontrol berkali-kali untuk kecelakaan yang
 * sama; kunjungan berikutnya menunjuk baris ini lewat suplesi.
 */
class AccidentRecord extends Model
{
    protected $table = 'integration.accident_records';

    protected $guarded = ['id'];

    public const KLL = 'kll';
    public const KLL_KERJA = 'kll-kerja';
    public const KERJA = 'kerja';
    public const LAINNYA = 'lainnya';

    public const JENIS = [self::KLL, self::KLL_KERJA, self::KERJA, self::LAINNYA];

    /** Jenis kejadian yang penjamin pertamanya PT Jasa Raharja. */
    public const DIJAMIN_JASA_RAHARJA = [self::KLL, self::KLL_KERJA];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'jasa_raharja_asked' => 'boolean',
            'jasa_raharja_asked_at' => 'datetime',
            'jasa_raharja_covered' => 'boolean',
            'jasa_raharja_ceiling' => 'decimal:2',
            'jasa_raharja_valid_until' => 'date',
            'jasa_raharja_response' => 'array',
        ];
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(AccidentSupplement::class, 'accident_record_id');
    }

    /** Apakah kejadian ini termasuk yang penjamin pertamanya Jasa Raharja. */
    public function involvesJasaRaharja(): bool
    {
        return in_array($this->accident_type, self::DIJAMIN_JASA_RAHARJA, true);
    }
}
