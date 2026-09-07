<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pembaca unit layanan milik konteks organization, lewat kontrak yang
 * diterbitkannya (organization.v_unit_summary).
 *
 * Dibuat saat pemeriksaan batas konteks diperketat: dua layar laporan
 * mengueri tabel unit lewat model milik organization, dan itu lolos selama
 * ini karena pemeriksaannya cuma memindai literal 'schema.tabel'. Daftar
 * unit untuk penyaring laporan memang cuma butuh nama dan penandanya —
 * persis yang sudah diterbitkan kontraknya.
 */
class OrganizationContext
{
    private const VIEW = 'organization.v_unit_summary';

    /** Unit layanan aktif, untuk daftar penyaring di layar laporan. */
    public function activeUnits(): Collection
    {
        return DB::table(self::VIEW)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
