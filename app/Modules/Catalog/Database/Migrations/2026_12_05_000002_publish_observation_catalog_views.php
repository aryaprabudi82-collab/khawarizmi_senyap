<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks catalog: katalog pengukuran & panel observasi
 * (domain M item D).
 *
 * Butir panel diterbitkan SUDAH TERGABUNG dengan kode pengukurannya dan
 * dengan rentang rujukan yang BERLAKU — yakni rentang panel bila diisi,
 * kalau tidak rentang bawaan kodenya. Penggabungan itu dilakukan di sini,
 * di satu tempat, bukan diserahkan ke tiap konsumen: aturan "rentang panel
 * mengalahkan rentang bawaan" yang ditemukan ulang di banyak tempat akan
 * benar di sebagian tempat saja, dan yang salah menghasilkan penanda
 * abnormal palsu pada bayi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW catalog.v_observation_code AS
            SELECT
                id      AS observation_code_id,
                code,
                display,
                unit,
                value_type,
                reference_low,
                reference_high,
                category,
                is_active
            FROM catalog.observation_codes');

        DB::statement('CREATE OR REPLACE VIEW catalog.v_observation_panel_item AS
            SELECT
                i.id                                    AS panel_item_id,
                p.code                                  AS panel_code,
                p.name                                  AS panel_name,
                p.care_context,
                p.age_group,
                p.is_active                             AS panel_is_active,
                c.code,
                c.display,
                c.unit,
                c.value_type,
                c.category,
                i.sequence,
                i.is_required,
                -- Rentang yang BERLAKU: panel mengalahkan bawaan.
                COALESCE(i.reference_low, c.reference_low)   AS reference_low,
                COALESCE(i.reference_high, c.reference_high) AS reference_high
            FROM catalog.observation_panel_items i
            JOIN catalog.observation_panels p ON p.id = i.observation_panel_id
            JOIN catalog.observation_codes c ON c.id = i.observation_code_id
            WHERE c.is_active');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.v_observation_panel_item');
        DB::statement('DROP VIEW IF EXISTS catalog.v_observation_code');
    }
};
