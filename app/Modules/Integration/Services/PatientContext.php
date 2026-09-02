<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks integration menyentuh data milik konteks
 * identity. Dibaca lewat identity.v_patient_summary — kontrak yang
 * diterbitkan konteks identity, termasuk alamat terstruktur yang dibutuhkan
 * resource Patient SATUSEHAT.
 */
class PatientContext
{
    private const VIEW = 'identity.v_patient_summary';

    public function find(int $patientId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $patientId)->first();
    }
}
