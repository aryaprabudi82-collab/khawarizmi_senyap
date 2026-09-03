<?php

namespace App\Modules\Inpatient\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks inpatient menyentuh data milik konteks
 * encounter. Dibaca lewat v_registration_summary — TIDAK PERNAH menulis
 * balik ke encounter.registrations, lihat catatan migrasi inpatient.
 */
class EncounterContext
{
    public function find(int $registrationId): ?object
    {
        return DB::table('encounter.v_registration_summary')
            ->where('id', $registrationId)
            ->first();
    }

    /** Registrasi care_type=ranap yang belum punya baris admisi. */
    public function awaitingAdmission(): Collection
    {
        return DB::table('encounter.v_registration_summary as r')
            ->where('r.care_type', 'ranap')
            ->whereNotIn('r.status', ['batal', 'selesai'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('inpatient.admissions as a')
                    ->whereColumn('a.registration_id', 'r.id');
            })
            ->orderBy('r.registered_at')
            ->get();
    }
}
