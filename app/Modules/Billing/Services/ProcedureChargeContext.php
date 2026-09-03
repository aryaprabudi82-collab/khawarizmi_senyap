<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks billing menyentuh data milik konteks clinical
 * untuk tindakan rawat jalan.
 *
 * Dibaca lewat clinical.v_procedure_charge — kontrak yang diterbitkan
 * konteks clinical, berisi tindakan yang sudah dicatat berikut nilainya.
 * Pola sama dengan PrescriptionChargeContext dan OrderChargeContext.
 */
class ProcedureChargeContext
{
    private const VIEW = 'clinical.v_procedure_charge';

    public function forRegistration(int $registrationId): Collection
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->get();
    }
}
