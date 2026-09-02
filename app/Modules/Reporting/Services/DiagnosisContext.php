<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks reporting menyentuh data milik konteks
 * clinical. Dibaca lewat clinical.v_encounter_diagnosis — kontrak yang
 * diterbitkan konteks clinical.
 */
class DiagnosisContext
{
    private const VIEW = 'clinical.v_encounter_diagnosis';

    public function forDate(CarbonInterface $date): Collection
    {
        return DB::table(self::VIEW)->whereDate('diagnosed_at', $date->toDateString())->get();
    }
}
