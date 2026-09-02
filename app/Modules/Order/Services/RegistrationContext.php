<?php

namespace App\Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks order menyentuh data milik konteks encounter.
 *
 * Dibaca lewat encounter.v_registration_summary — sengaja kelas terpisah dari
 * RegistrationContext milik clinical dan billing, walau bentuknya sama:
 * tiap konteks memegang kopling lintas konteksnya sendiri.
 */
class RegistrationContext
{
    private const VIEW = 'encounter.v_registration_summary';

    public function find(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $registrationId)->first();
    }
}
