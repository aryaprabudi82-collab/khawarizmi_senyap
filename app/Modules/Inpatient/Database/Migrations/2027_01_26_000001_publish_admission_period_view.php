<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak periode rawat inap untuk surat keterangan (domain P item C).
 *
 * Surat keterangan rawat inap dipakai untuk klaim asuransi dan izin
 * kerja. Kalau tanggalnya diketik ulang di layar surat, ia bisa berbeda
 * dari tanggal admisi tanpa ada yang tahu — dan yang harus menyangkal
 * suratnya sendiri belakangan adalah rumah sakit. Karena itu tanggalnya
 * DISALIN dari sini, bukan diterima dari pengisi formulir.
 *
 * YANG SENGAJA TIDAK IKUT DITERBITKAN: DPJP, bed, kelas, cara pulang, dan
 * catatan admisi. Surat keterangan rawat inap menjawab satu pertanyaan —
 * kapan pasien ini dirawat — dan menyertakan sisanya berarti menyerahkan
 * rincian perawatan kepada perusahaan asuransi lewat pintu yang tidak
 * pernah dimaksudkan untuk itu.
 *
 * `discharged_at` boleh kosong: pasien yang masih dirawat memang belum
 * punya tanggal pulang, dan surat untuknya harus berbunyi "sampai saat
 * ini masih dalam perawatan" — bukan tanggal karangan.
 *
 * TIDAK ADA PENYARING STATUS DI SINI, dan itu hasil pemeriksaan, bukan
 * kelalaian. Draf pertama menyaring `status <> 'batal'` mengikuti
 * kebiasaan kontrak-kontrak lain. `pg_constraint` menunjukkan
 * admissions_status_check cuma mengenal 'dirawat' dan 'pulang' — tidak
 * ada admisi batal. Penyaring untuk keadaan yang tidak ada tidak
 * menyaring apa pun, tapi ia MEMBERI TAHU pembaca berikutnya bahwa
 * keadaan itu ada, dan pembaca itu akan menulis kode yang menanganinya.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_admission_period AS
            SELECT
                a.id                AS admission_id,
                a.admission_number,
                a.registration_id,
                a.patient_id,
                a.patient_mrn,
                a.patient_name,
                a.admitted_at,
                a.discharged_at,
                a.status
            FROM '.self::S.'.admissions a');

        DB::statement('COMMENT ON VIEW '.self::S.".v_admission_period IS
            'Periode rawat inap (masuk/keluar) tanpa rincian klinis — untuk surat keterangan rawat inap, domain P item C. Status hanya dirawat/pulang; tidak ada admisi batal.'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_admission_period');
    }
};
