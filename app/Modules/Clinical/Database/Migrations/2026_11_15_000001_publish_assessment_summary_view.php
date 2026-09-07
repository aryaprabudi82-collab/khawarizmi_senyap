<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks clinical: ASESMEN berikut empat bagian SOAP-nya.
 *
 * Dipakai integration menyusun ClinicalImpression (bagian "A" — penilaian
 * klinis dokter) dan CarePlan (bagian "P" — rencana tindak lanjutnya).
 * Keduanya memang berasal dari satu catatan yang sama, dan memisahkannya
 * jadi dua kontrak akan membuat keduanya bisa berbeda versi.
 *
 * STATUS DAN WAKTU FINALISASI IKUT DITERBITKAN, dan itu yang paling
 * menentukan. Asesmen yang masih draf boleh berubah kapan saja; mengirim
 * penilaian klinis yang belum selesai ke platform nasional berarti
 * menyebarkan pendapat dokter yang belum ia nyatakan selesai — dan
 * fasilitas lain tidak punya cara membedakannya dari yang sudah final.
 * Penyaringnya diletakkan di konsumen, tapi bahannya diterbitkan di sini.
 *
 * NOMOR VERSI IKUT DITERBITKAN supaya pengiriman ulang atas asesmen yang
 * sudah direvisi bisa dikenali sebagai revisi, bukan sebagai catatan baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW clinical.v_assessment_summary AS
            SELECT
                id                  AS assessment_id,
                registration_id,
                patient_id,
                registration_number,
                patient_mrn,
                patient_name,
                unit_name,
                kind,
                chief_complaint,
                subjective,
                objective,
                assessment,
                plan,
                practitioner_id,
                practitioner_name,
                recorded_at,
                status,
                version,
                finalized_at
            FROM clinical.assessments
            WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS clinical.v_assessment_summary');
    }
};
