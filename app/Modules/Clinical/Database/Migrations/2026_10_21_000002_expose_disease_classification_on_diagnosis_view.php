<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item B: klasifikasi penyakit ikut dipaparkan ke reporting.
 *
 * v_encounter_diagnosis sebelumnya hanya memaparkan kode dan namanya.
 * Laporan morbiditas butuh sifat penularannya, dan bab ICD-10 untuk
 * pengelompokan RL 4A/4B nanti.
 *
 * Kode diagnosis yang belum terdaftar di kamus ikut dipaparkan dengan
 * transmission 'tidak-diketahui' lewat LEFT JOIN, bukan dibuang: diagnosis
 * yang benar-benar dicatat pada pasien tidak boleh hilang dari laporan
 * hanya karena kamusnya belum lengkap. Laporannya lebih baik menunjukkan
 * ada yang belum terklasifikasi daripada diam-diam melaporkan angka yang
 * lebih kecil.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_encounter_diagnosis');

        DB::statement('CREATE VIEW ' . self::S . '.v_encounter_diagnosis AS
            SELECT d.registration_id,
                   d.registration_number,
                   d.patient_id,
                   d.code,
                   d.display,
                   d.rank,
                   d.certainty,
                   d.diagnosed_at,
                   coalesce(k.transmission, \'tidak-diketahui\') AS transmission,
                   k.chapter
              FROM ' . self::S . '.diagnoses d
         LEFT JOIN ' . self::S . '.diagnosis_codes k ON k.code = d.code
             WHERE d.deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_encounter_diagnosis');

        DB::statement('CREATE VIEW ' . self::S . '.v_encounter_diagnosis AS
            SELECT registration_id, registration_number, patient_id, code, display, rank, certainty, diagnosed_at
              FROM ' . self::S . '.diagnoses
             WHERE deleted_at IS NULL');
    }
};
