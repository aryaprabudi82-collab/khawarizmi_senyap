<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks billing menyentuh data rawat inap.
 *
 * Dibaca lewat inpatient.v_room_charge — kontrak yang diterbitkan konteks
 * inpatient, satu baris per hari menginap. Pola sama dengan
 * ProcedureChargeContext dan OperationChargeContext.
 */
class RoomChargeContext
{
    private const VIEW = 'inpatient.v_room_charge';

    public function forRegistration(int $registrationId): Collection
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->orderBy('charge_date')
            ->get();
    }
}
