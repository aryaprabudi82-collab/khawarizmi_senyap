<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain J item C: kelompok DTD untuk RL 4A/4B.
 *
 * RL 4A dan 4B melaporkan morbiditas menurut Daftar Tabulasi Dasar (DTD)
 * — daftar kelompok sebab penyakit yang ditetapkan Kemenkes, bukan bab
 * ICD-10. Satu kode ICD-10 masuk TEPAT SATU kelompok DTD, berbeda dari
 * keanggotaan program surveilans di item B yang bisa lebih dari satu.
 * Karena itu bentuknya kolom, bukan tabel keanggotaan — mengikuti aturan
 * yang sama yang sudah dipakai memutuskan bentuk klasifikasi penularan.
 *
 * Kolomnya sengaja dibiarkan KOSONG. Daftar DTD resmi harus diimpor
 * bersama kamus ICD-10, dan menebaknya akan menghasilkan laporan wajib
 * yang isinya karangan — kesalahan yang jauh lebih berat pada laporan
 * yang dikirim ke Kemenkes daripada pada laporan internal. Selama kolom
 * ini kosong, RL 4A/4B dikelompokkan menurut bab ICD-10 dan layarnya
 * menyatakan hal itu terang-terangan, bukan menyamarkannya sebagai DTD.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::table(self::S . '.diagnosis_codes', function (Blueprint $table) {
            $table->string('dtd_group', 60)->nullable()
                ->comment('Kelompok Daftar Tabulasi Dasar Kemenkes untuk RL 4A/4B; null = belum diimpor');
        });

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
                   k.chapter,
                   k.dtd_group
              FROM ' . self::S . '.diagnoses d
         LEFT JOIN ' . self::S . '.diagnosis_codes k ON k.code = d.code
             WHERE d.deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_encounter_diagnosis');

        DB::statement('CREATE VIEW ' . self::S . '.v_encounter_diagnosis AS
            SELECT d.registration_id, d.registration_number, d.patient_id, d.code, d.display,
                   d.rank, d.certainty, d.diagnosed_at,
                   coalesce(k.transmission, \'tidak-diketahui\') AS transmission, k.chapter
              FROM ' . self::S . '.diagnoses d
         LEFT JOIN ' . self::S . '.diagnosis_codes k ON k.code = d.code
             WHERE d.deleted_at IS NULL');

        Schema::table(self::S . '.diagnosis_codes', function (Blueprint $table) {
            $table->dropColumn('dtd_group');
        });
    }
};
