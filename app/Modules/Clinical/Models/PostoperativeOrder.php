<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Instruksi pasca-operasi yang dibawa pasien ke bangsal.
 *
 * Melekat pada OPERASI, bukan pada kunjungan — sama seperti catatan
 * anestesinya.
 */
class PostoperativeOrder extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.postoperative_orders';

    protected $guarded = ['id'];

    /** Bagian instruksi; dua terakhir tidak ada di Khanza. */
    public const BAGIAN = [
        'care_location' => 'Dirawat di',
        'fluids' => 'Cairan',
        'antibiotics' => 'Antibiotika',
        'analgesics' => 'Analgetika',
        'other_medication' => 'Medikamentosa lain',
        'diet' => 'Diet',
        'laboratory' => 'Pemeriksaan laboratorium',
        'transfusion' => 'Transfusi',
        'mobilisation' => 'Mobilisasi',
        'wound_care' => 'Perawatan luka',
        'other' => 'Lain-lain',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
        ];
    }

    /**
     * Bagian yang terisi.
     *
     * @return array<int, string>
     */
    public function filledParts(): array
    {
        $terisi = [];

        foreach (self::BAGIAN as $kolom => $label) {
            if (filled($this->{$kolom})) {
                $terisi[] = $label;
            }
        }

        return $terisi;
    }
}
