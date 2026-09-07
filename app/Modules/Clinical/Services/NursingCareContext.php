<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca master masalah & rencana keperawatan milik konteks catalog,
 * lewat kontrak yang diterbitkannya.
 *
 * Rencana selalu dibaca BERSAMA kode masalah induknya, karena aturan
 * "rencana melekat pada masalahnya" ditegakkan di sini — dan menegakkannya
 * mustahil kalau hierarkinya tidak ikut terbaca.
 */
class NursingCareContext
{
    private const MASALAH = 'catalog.v_nursing_problem';
    private const RENCANA = 'catalog.v_nursing_care_plan';

    public function problem(string $code, ?string $specialty = null): ?stdClass
    {
        return DB::table(self::MASALAH)
            ->where('code', $code)
            ->where('specialty', $specialty)
            ->first();
    }

    /**
     * Masalah aktif untuk satu spesialisasi, berikut yang berlaku umum.
     *
     * Perawat anak tetap menegakkan masalah yang berlaku untuk siapa saja;
     * memisahkannya akan memaksa master umum disalin ke tiap spesialisasi.
     */
    public function activeProblems(?string $specialty = null): Collection
    {
        return DB::table(self::MASALAH)
            ->where('is_active', true)
            ->when($specialty !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('specialty', $specialty)->orWhereNull('specialty')
            ))
            ->orderBy('code')
            ->get();
    }

    /** Rencana aktif di bawah satu masalah, dicari menurut kodenya. */
    public function activeCarePlans(string $problemCode, ?string $specialty = null): Collection
    {
        return DB::table(self::RENCANA)
            ->where('problem_code', $problemCode)
            ->where('specialty', $specialty)
            ->where('is_active', true)
            ->where('problem_is_active', true)
            ->orderBy('code')
            ->get();
    }

    /**
     * Satu rencana, dipastikan memang berada di bawah masalah yang dimaksud.
     *
     * Pemeriksaan induknya dilakukan di kueri, bukan sesudahnya: rencana
     * yang diambil tanpa menyebut induknya akan lolos begitu ada dua master
     * memakai kode rencana yang sama.
     */
    public function carePlan(string $problemCode, string $planCode, ?string $specialty = null): ?stdClass
    {
        return DB::table(self::RENCANA)
            ->where('problem_code', $problemCode)
            ->where('code', $planCode)
            ->where('specialty', $specialty)
            ->first();
    }
}
