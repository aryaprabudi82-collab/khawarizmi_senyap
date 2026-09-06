<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca data konteks clinical untuk keperluan integration, lewat
 * clinical.v_encounter_diagnosis — kontrak yang diterbitkan konteks
 * clinical, bukan tabelnya.
 *
 * Kelas ini menampung pencarian diagnosis PER KUNJUNGAN, yang dipakai
 * hampir seluruh alur kirim. Satu pengecualian yang disengaja: pencarian
 * calon PRB di PrbService merentang tiga konteks sekaligus (diagnosis
 * clinical, kunjungan encounter, dan SEP milik integration sendiri),
 * sehingga tidak bisa jadi milik pembaca satu konteks — tapi tetap membaca
 * view terbitan yang sama, bukan tabel clinical.
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
