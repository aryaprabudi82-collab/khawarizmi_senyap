<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks orders: HASIL pemeriksaan penunjang per butir.
 *
 * v_order_summary sudah menerbitkan satu baris per PERMINTAAN, cukup untuk
 * menghitung jumlah dan lama pelayanan. Yang belum diterbitkan adalah
 * hasilnya sendiri — dan itulah yang dibutuhkan SATUSEHAT: Observation,
 * DiagnosticReport, dan Specimen semuanya berbicara tentang satu butir
 * pemeriksaan, bukan tentang keseluruhan lembar permintaan.
 *
 * SPECIMEN DAN MODALITAS IKUT DITERBITKAN karena keduanya menentukan
 * resource FHIR mana yang boleh disusun sama sekali. Pemeriksaan
 * laboratorium punya bahan yang diperiksa (darah, urin, dahak); foto
 * rontgen tidak punya — ia punya modalitas. Menyusun Specimen untuk foto
 * toraks berarti melaporkan bahan pemeriksaan yang tidak pernah diambil
 * dari pasien, ke platform nasional.
 *
 * VERIFIKASI IKUT DITERBITKAN supaya konsumen bisa menolak mengirim hasil
 * yang belum diverifikasi. Hasil yang belum diverifikasi masih bisa
 * berubah; hasil yang sudah tersebar nasional lalu berubah jauh lebih
 * sulit ditarik daripada hasil yang dikirim terlambat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW orders.v_order_result AS
            SELECT
                i.id                        AS result_id,
                o.id                        AS order_id,
                o.order_number,
                o.registration_id,
                o.patient_id,
                o.patient_mrn,
                o.patient_name,
                o.category,
                o.status,
                o.clinical_notes,
                o.requesting_practitioner_id,
                o.requesting_practitioner_name,
                o.requested_at,
                o.resulted_at,
                o.verified_at,
                o.verified_by_name,
                i.test_id,
                i.test_code,
                i.test_name,
                i.result_type,
                i.unit,
                i.reference_low,
                i.reference_high,
                i.reference_text,
                i.result_numeric,
                i.result_text,
                i.result_notes,
                i.is_abnormal,
                i.entered_at,
                i.entered_by_name,
                c.specimen_type,
                c.modality
            FROM orders.orders o
            JOIN orders.order_items i ON i.order_id = o.id
            LEFT JOIN orders.test_catalog c ON c.id = i.test_id
            WHERE o.deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS orders.v_order_result');
    }
};
