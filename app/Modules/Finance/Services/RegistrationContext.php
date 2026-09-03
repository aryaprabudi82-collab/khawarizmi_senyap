<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks finance menyentuh data milik konteks encounter.
 *
 * Dibaca lewat encounter.v_registration_summary — sengaja kelas terpisah
 * dari RegistrationContext milik clinical/order/billing, walau bentuknya
 * sama: tiap konteks memegang kopling lintas konteksnya sendiri.
 */
class RegistrationContext
{
    private const VIEW = 'encounter.v_registration_summary';

    public function find(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $registrationId)->first();
    }

    /**
     * Pencarian kunjungan untuk formulir deposit/perkiraan biaya —
     * konteks finance tidak punya layar kunjungan sendiri, jadi
     * pencarian ini yang menggantikan peran registrasi.index di sana.
     *
     * @return Collection<int, stdClass>
     */
    public function search(string $term, ?string $careType = null): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        return DB::table(self::VIEW)
            ->when($careType, fn ($q) => $q->where('care_type', $careType))
            ->where(function ($q) use ($term) {
                $q->where('registration_number', 'ilike', '%' . $term . '%')
                    ->orWhere('patient_mrn', 'ilike', '%' . $term . '%')
                    ->orWhere('patient_name', 'ilike', '%' . $term . '%');
            })
            ->orderByDesc('registered_at')
            ->limit(20)
            ->get();
    }
}
