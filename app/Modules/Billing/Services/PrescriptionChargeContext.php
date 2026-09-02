<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks billing menyentuh data milik konteks pharmacy.
 *
 * Dibaca lewat pharmacy.v_prescription_charge — kontrak yang diterbitkan
 * konteks pharmacy, berisi obat yang sudah diserahkan berikut nilainya.
 */
class PrescriptionChargeContext
{
    private const VIEW = 'pharmacy.v_prescription_charge';

    /** Obat yang sudah diserahkan untuk satu kunjungan. */
    public function forRegistration(int $registrationId): Collection
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->get();
    }
}
