<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks billing menyentuh data milik konteks clinical
 * untuk operasi.
 *
 * Dibaca lewat clinical.v_operation_charge — kontrak yang diterbitkan
 * konteks clinical, berisi operasi yang sudah dicatat berikut nilainya.
 * Pola sama dengan ProcedureChargeContext.
 */
class OperationChargeContext
{
    private const VIEW = 'clinical.v_operation_charge';

    public function forRegistration(int $registrationId): Collection
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->get();
    }
}
