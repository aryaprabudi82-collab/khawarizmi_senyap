<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks billing menyentuh data milik konteks encounter.
 *
 * Dibaca lewat encounter.v_registration_summary. Sengaja kelas terpisah dari
 * RegistrationContext milik konteks clinical, walau bentuknya mirip — tiap
 * konteks memegang kopling lintas konteksnya sendiri, supaya perubahan pada
 * satu konsumen tidak menyeret konsumen lain yang kebetulan memakai view
 * yang sama.
 */
class RegistrationContext
{
    private const VIEW = 'encounter.v_registration_summary';

    public function find(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $registrationId)->first();
    }
}
