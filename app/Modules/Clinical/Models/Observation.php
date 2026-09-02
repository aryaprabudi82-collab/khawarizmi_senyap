<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tanda vital dan pengukuran.
 *
 * Tabelnya dipartisi per bulan dengan primary key (id, observed_at). Eloquent
 * cukup memakai id karena identity-nya unik lintas partisi, dan baris observasi
 * tidak pernah diubah — hanya ditambah.
 */
class Observation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clinical.observations';

    protected $guarded = ['id'];

    /** Katalog pengukuran yang dipakai layar pemeriksaan rawat jalan. */
    public const CATALOG = [
        'tekanan-darah-sistolik'  => ['Tekanan darah sistolik', 'mmHg', 90, 140],
        'tekanan-darah-diastolik' => ['Tekanan darah diastolik', 'mmHg', 60, 90],
        'nadi'                    => ['Denyut nadi', 'x/menit', 60, 100],
        'laju-napas'              => ['Laju napas', 'x/menit', 12, 20],
        'suhu'                    => ['Suhu tubuh', '°C', 36.0, 37.5],
        'saturasi-oksigen'        => ['Saturasi oksigen', '%', 95, 100],
        'berat-badan'             => ['Berat badan', 'kg', null, null],
        'tinggi-badan'            => ['Tinggi badan', 'cm', null, null],
        'skala-nyeri'             => ['Skala nyeri', '0-10', 0, 3],
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'value_numeric' => 'decimal:2',
            'is_abnormal' => 'boolean',
        ];
    }

    /** Apakah nilai berada di luar rentang rujukan katalog. */
    public static function isAbnormal(string $code, ?float $value): bool
    {
        if ($value === null || ! isset(self::CATALOG[$code])) {
            return false;
        }

        [, , $min, $max] = self::CATALOG[$code];

        if ($min === null || $max === null) {
            return false;
        }

        return $value < $min || $value > $max;
    }
}
