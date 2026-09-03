<?php

namespace App\Modules\Quality\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks quality menyentuh data milik konteks
 * organization. Dibaca lewat v_unit_summary — dipakai memilih unit tempat
 * insiden terjadi atau lokasi proyek ICRA.
 */
class OrganizationContext
{
    public function units(): Collection
    {
        return DB::table('organization.v_unit_summary')->where('is_active', true)->orderBy('name')->get();
    }
}
