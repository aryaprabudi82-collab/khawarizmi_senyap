<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jenis imunisasi.
 *
 * JUMLAH DOSIS DAN JARAKNYA IKUT DICATAT — keduanya tidak ada di
 * master_imunisasi Khanza, dan tanpa keduanya jadwal dosis berikutnya
 * seorang anak tidak bisa dihitung sistem.
 */
class ImmunisationType extends Model
{
    protected $table = 'catalog.immunisation_types';

    protected $guarded = ['id'];

    public const RUTE = [
        'intramuskular' => 'Intramuskular',
        'subkutan' => 'Subkutan',
        'intradermal' => 'Intradermal',
        'oral' => 'Oral',
        'intranasal' => 'Intranasal',
    ];

    protected function casts(): array
    {
        return [
            'is_national_programme' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Vaksin yang diulang tanpa batas dosis, seperti influenza tahunan. */
    public function isOpenEnded(): bool
    {
        return $this->total_doses === null;
    }
}
