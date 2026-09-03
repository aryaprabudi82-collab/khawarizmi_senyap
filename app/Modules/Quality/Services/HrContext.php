<?php

namespace App\Modules\Quality\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks quality menyentuh data milik konteks hr.
 * Dibaca lewat hr.v_employee_summary — kontrak baca pertama yang
 * diterbitkan hr, ditambahkan bersamaan dengan kebutuhan ini (lihat
 * migrasi 2026_09_04_000001_publish_hr_employee_view).
 */
class HrContext
{
    public function employees(): Collection
    {
        return DB::table('hr.v_employee_summary')->orderBy('name')->get();
    }
}
