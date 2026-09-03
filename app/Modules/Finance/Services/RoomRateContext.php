<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks finance menyentuh data milik konteks inpatient.
 *
 * Dibaca lewat inpatient.v_room_class_rate — dipakai perkiraan_biaya_ranap
 * untuk mengambil tarif rata-rata kamar per kelas tanpa finance menyentuh
 * inpatient.rooms langsung.
 */
class RoomRateContext
{
    private const VIEW = 'inpatient.v_room_class_rate';

    /** @return Collection<int, \stdClass> */
    public function all(): Collection
    {
        return DB::table(self::VIEW)->orderBy('room_class')->get();
    }

    public function rateFor(string $roomClass): ?float
    {
        $baris = DB::table(self::VIEW)->where('room_class', $roomClass)->first();

        return $baris !== null ? (float) $baris->avg_daily_rate : null;
    }
}
