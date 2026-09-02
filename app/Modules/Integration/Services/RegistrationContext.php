<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks integration menyentuh data milik konteks
 * encounter. Dibaca lewat encounter.v_registration_summary — kontrak yang
 * diterbitkan konteks encounter.
 */
class RegistrationContext
{
    private const VIEW = 'encounter.v_registration_summary';

    public function find(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $registrationId)->first();
    }
}
