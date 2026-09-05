<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item C: dua kontrak clinical yang dibutuhkan laporan RL.
 *
 * Uji batas konteks menangkap StatutoryReportService membaca langsung
 * clinical.operations dan clinical.diagnosis_codes. Keduanya memang milik
 * clinical, jadi yang benar bukan melonggarkan aturannya melainkan
 * menerbitkan apa yang boleh dibaca konteks lain.
 *
 * v_operation_charge yang sudah ada TIDAK bisa dipakai RL 3.6: bentuknya
 * untuk penagihan (registration_id, item, nilai) dan tidak membawa jenis
 * anestesi, kamar operasi, maupun patient_id yang justru menjadi isi
 * formulir RL 3.6. Menambahkan kolom itu ke view penagihan akan mencampur
 * dua kebutuhan yang berbeda pada satu kontrak, jadi kegiatan pembedahan
 * diterbitkan sebagai view tersendiri.
 *
 * v_diagnosis_code menerbitkan kamus ICD-10-nya sendiri — bukan diagnosis
 * pasien (itu sudah v_encounter_diagnosis), melainkan daftar kodenya —
 * supaya laporan bisa memeriksa apakah DTD sudah diimpor dan berapa kode
 * yang belum berkelompok. Angka itu wajib terlihat sebelum RL 4 dikirim.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_operation_summary AS
            SELECT o.id,
                   o.registration_id,
                   o.patient_id,
                   o.service_name,
                   o.surgeon_name,
                   o.anesthesia_type,
                   o.operating_room,
                   o.performed_at
              FROM ' . self::S . '.operations o');

        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_diagnosis_code AS
            SELECT k.code,
                   k.display,
                   k.chapter,
                   k.transmission,
                   k.dtd_group,
                   k.is_active
              FROM ' . self::S . '.diagnosis_codes k');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_operation_summary');
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_diagnosis_code');
    }
};
