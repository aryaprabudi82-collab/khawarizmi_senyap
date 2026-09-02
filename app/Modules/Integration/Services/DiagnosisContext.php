<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks integration menyentuh data milik konteks
 * clinical. Dibaca lewat clinical.v_encounter_diagnosis — kontrak yang
 * diterbitkan konteks clinical.
 */
class DiagnosisContext
{
    private const VIEW = 'clinical.v_encounter_diagnosis';

    /** Diagnosis utama satu kunjungan — dipakai sebagai diagnosa_awal SEP dan resource Condition SATUSEHAT. */
    public function primaryFor(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->where('rank', 'utama')
            ->orderByDesc('diagnosed_at')
            ->first();
    }

    public function allFor(int $registrationId): Collection
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->orderBy('diagnosed_at')
            ->get();
    }
}
