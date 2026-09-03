<?php

namespace App\Modules\Inpatient\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Satu-satunya tempat konteks inpatient menyentuh data milik konteks organization. */
class OrganizationContext
{
    public function units(): Collection
    {
        return DB::table('organization.v_unit_summary')->where('is_active', true)->orderBy('name')->get();
    }

    /** Praktisi aktif — dipakai memilih DPJP pengganti saat alih rawat. */
    public function practitioners(): Collection
    {
        return DB::table('organization.v_practitioner_summary')->where('is_active', true)->orderBy('name')->get();
    }

    public function findPractitioner(int $id): ?object
    {
        return DB::table('organization.v_practitioner_summary')->where('id', $id)->first();
    }
}
