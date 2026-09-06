<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item E: skrining awal rawat jalan diterbitkan untuk laporan.
 *
 * clinical.screenings sudah ada sejak modul rekam medis (skrining awal
 * rawat jalan, Permenkes 24/2022), jadi skrining_ralan_pernapasan_pertahun
 * tidak butuh pencatatan baru — cukup kontraknya.
 *
 * Yang dipaparkan hanya kolom yang dibutuhkan laporan, bukan seluruh isi
 * skrining: hasil skrining nyeri dan risiko jatuh adalah data klinis yang
 * tidak ada urusannya dengan laporan tahunan gejala pernapasan.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_screening_summary AS
            SELECT s.id, s.registration_id, s.patient_id,
                   s.infectious_symptom, s.nutrition_at_risk, s.screened_at
              FROM ' . self::S . '.screenings s');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_screening_summary');
    }
};
