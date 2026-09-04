<?php

namespace App\Modules\Pharmacy\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks pharmacy menyentuh data milik konteks
 * organization. Dibaca lewat v_unit_summary — dipakai memilih unit
 * pemohon saat mengajukan pengajuan_barang_medis. Pola sama dengan
 * Inventory\Services\OrganizationContext.
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
