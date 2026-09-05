<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item A: kontrak baca admisi untuk konteks reporting.
 *
 * Sampai sekarang inpatient hanya menerbitkan v_room_class_rate (tarif
 * rata-rata) dan v_room_charge (biaya per hari) — keduanya soal uang.
 * Laporan sensus butuh yang lain: siapa dirawat di ruang mana, datang
 * dari poli/dokter mana, sejak kapan, dan sudah pulang atau belum.
 *
 * Kelas kamar dan nama ruang ikut dipaparkan supaya reporting tidak perlu
 * menyeberang lagi ke inpatient.rooms — satu kontrak, satu pembacaan.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_admission_summary AS
            SELECT a.id              AS admission_id,
                   a.admission_number,
                   a.registration_id,
                   a.patient_id,
                   a.patient_mrn,
                   a.patient_name,
                   a.dpjp_practitioner_id,
                   a.dpjp_name,
                   a.admitted_at,
                   a.discharged_at,
                   a.discharge_status,
                   a.status,
                   r.room_number,
                   r.room_class,
                   r.unit_id        AS room_unit_id,
                   r.unit_name      AS room_unit_name,
                   b.bed_number
              FROM ' . self::S . '.admissions a
              JOIN ' . self::S . '.beds  b ON b.id = a.bed_id
              JOIN ' . self::S . '.rooms r ON r.id = b.room_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_admission_summary');
    }
};
