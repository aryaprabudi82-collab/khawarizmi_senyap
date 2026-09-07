<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Konseling farmasi — percakapan apoteker dengan pasien tentang obatnya.
 *
 * Daftar obat dan alergi di sini SALINAN dari yang sudah tercatat, bukan
 * satu kolom teks panjang seperti obat_pemakaian varchar(700) Khanza.
 */
class PharmacyCounselling extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.pharmacy_counsellings';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    protected function casts(): array
    {
        return [
            'medications' => 'array',
            'allergies' => 'array',
            'is_repeat_visit' => 'boolean',
            'counselled_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }
}
