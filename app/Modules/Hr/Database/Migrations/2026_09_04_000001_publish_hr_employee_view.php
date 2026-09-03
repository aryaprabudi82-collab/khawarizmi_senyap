<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * hr belum punya konsumen lintas konteks sampai quality butuh memilih
 * pegawai saat mencatat insiden K3 — kontrak baca pertama untuk hr,
 * mengikuti pola yang sama seperti setiap view terbitan lain di sistem
 * ini (mis. organization.v_unit_summary, identity.v_patient_summary).
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_employee_summary AS
            SELECT id, employee_number, name, position, unit_id, is_active
            FROM ' . self::S . '.employees
            WHERE is_active = true');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_employee_summary');
    }
};
