<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks reporting menyentuh data milik konteks
 * encounter. Dibaca lewat encounter.v_registration_summary — kontrak yang
 * diterbitkan konteks encounter.
 */
class RegistrationContext
{
    private const VIEW = 'encounter.v_registration_summary';

    public function forDate(CarbonInterface $date): Collection
    {
        return DB::table(self::VIEW)->whereDate('service_date', $date->toDateString())->get();
    }
}
