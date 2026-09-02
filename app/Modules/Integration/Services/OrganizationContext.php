<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks integration menyentuh data milik konteks
 * organization. Dibaca lewat v_unit_summary/v_practitioner_summary —
 * dipakai layar admin untuk memetakan unit/praktisi ke ID eksternal
 * (kode poli BPJS, Location/Practitioner ID SATUSEHAT).
 */
class OrganizationContext
{
    public function units(): Collection
    {
        return DB::table('organization.v_unit_summary')->where('is_active', true)->orderBy('name')->get();
    }

    public function practitioners(): Collection
    {
        return DB::table('organization.v_practitioner_summary')->where('is_active', true)->orderBy('name')->get();
    }
}
