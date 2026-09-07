<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks catalog: master masalah & rencana keperawatan
 * (domain M item B).
 *
 * Rencana diterbitkan BERIKUT kode masalah induknya, bukan sebagai daftar
 * datar. Konsumen harus bisa menegakkan aturan bahwa rencana melekat pada
 * masalahnya tanpa perlu menanyakan dua kali — dan kontrak yang menyembunyikan
 * hierarki itu akan membuat setiap konsumen menemukan ulang aturannya
 * sendiri, sebagian dengan benar dan sebagian tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW catalog.v_nursing_problem AS
            SELECT
                id          AS problem_id,
                code,
                name,
                specialty,
                standard_code,
                definition,
                is_active
            FROM catalog.nursing_problems');

        DB::statement('CREATE OR REPLACE VIEW catalog.v_nursing_care_plan AS
            SELECT
                r.id                AS plan_id,
                r.nursing_problem_id,
                m.code              AS problem_code,
                m.specialty,
                r.code,
                r.plan,
                r.standard_code,
                r.is_active,
                m.is_active         AS problem_is_active
            FROM catalog.nursing_care_plans r
            JOIN catalog.nursing_problems m ON m.id = r.nursing_problem_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.v_nursing_care_plan');
        DB::statement('DROP VIEW IF EXISTS catalog.v_nursing_problem');
    }
};
