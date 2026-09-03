<?php

namespace App\Modules\Asset\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks asset menyentuh data milik konteks
 * organization. Dibaca lewat v_unit_summary — dipakai memilih unit
 * asal/tujuan sirkulasi CSSD.
 */
class OrganizationContext
{
    public function units(): Collection
    {
        return DB::table('organization.v_unit_summary')->where('is_active', true)->orderBy('name')->get();
    }

    public function find(int $unitId): ?stdClass
    {
        return DB::table('organization.v_unit_summary')->where('id', $unitId)->first();
    }
}
