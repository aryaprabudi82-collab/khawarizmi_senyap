<?php

namespace App\Modules\Hr\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks hr menyentuh data milik konteks organization.
 * Dibaca lewat v_unit_summary/v_practitioner_summary — dipakai layar
 * pegawai untuk memilih unit kerja dan (opsional) menghubungkan pegawai ke
 * catatan praktisi kliniknya.
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
