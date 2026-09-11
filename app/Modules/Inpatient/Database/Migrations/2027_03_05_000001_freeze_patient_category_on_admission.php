<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori pasien DIBEKUKAN pada admisi (temuan verifikasi domain M).
 *
 * APA YANG SALAH. Ketiga kode laporan klasifikasi pasien ranap
 * (harian/bulanan/perbangsal) dilayani AncillaryReportService::
 * inpatientClass(), dan kolom yang dikelompokkannya adalah
 * `identity.patients.inpatient_classification` — ATRIBUT PASIEN yang bisa
 * diubah kapan saja lewat layar pasien. Akibatnya rekap klasifikasi bulan
 * lalu BERUBAH SENDIRI begitu pasiennya dikategorikan ulang tahun ini.
 *
 * Ini bentuk kesalahan yang sama yang sudah ditolak berkali-kali di
 * proyek ini — tarif yang dibaca ulang saat laporan dibuat, HPP yang
 * tidak dibekukan per baris, denda yang disimpan sebagai angka mati. Yang
 * membuatnya lolos di sini: angkanya selalu terlihat wajar. Tidak ada
 * baris yang hilang dan tidak ada total yang meleset; yang berubah cuma
 * pembagiannya, dan tidak ada yang menghafal pembagian bulan lalu.
 *
 * MAKA KATEGORINYA DISALIN KE ADMISI saat pasien masuk, dan laporan
 * membaca salinan itu. Admisi adalah PERISTIWA — begitu terjadi, isinya
 * tidak berubah lagi.
 *
 * DAN SATU HAL YANG PERLU DILURUSKAN TENTANG NAMANYA. Kolom ini berisi
 * Umum/Prioritas/Isolasi/VIP: itu KATEGORI PASIEN, bukan klasifikasi
 * ketergantungan keperawatan (minimal/parsial/total care) yang dimaksud
 * `klasifikasi_pasien_ranap` Khanza dan yang dipakai menghitung kebutuhan
 * tenaga perawat per sif. Yang kedua memang belum ada, berubah tiap hari
 * selama dirawat, dan tidak bisa diwakili satu nilai pada admisi —
 * tercatat tersendiri di registri disposisi sebagai yang belum dibangun.
 * Kolomnya dinamai `patient_category`, bukan `classification`, supaya
 * yang membacanya nanti tidak mengira pertanyaan kedua sudah terjawab.
 *
 * BARIS LAMA DIISI DARI NILAI PASIEN SAAT INI, dan itu memang tebakan —
 * satu-satunya nilai yang ada. Tapi tebakan yang dibekukan sekali jauh
 * lebih baik daripada nilai benar yang terus berubah: mulai hari ini
 * angkanya berhenti bergerak.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        Schema::table(self::S.'.admissions', function (Blueprint $table) {
            $table->string('patient_category', 60)->nullable()
                ->comment('Kategori pasien (Umum/Prioritas/Isolasi/VIP) DIBEKUKAN saat masuk — '
                    .'bukan klasifikasi ketergantungan keperawatan');
        });

        DB::statement('UPDATE '.self::S.'.admissions a
                          SET patient_category = p.inpatient_classification
                         FROM identity.patients p
                        WHERE p.id = a.patient_id
                          AND a.patient_category IS NULL');

        $this->rebuildView();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_admission_summary');

        Schema::table(self::S.'.admissions', function (Blueprint $table) {
            $table->dropColumn('patient_category');
        });

        $this->rebuildView(false);
    }

    /**
     * Kolom baru ditaruh DI BELAKANG: CREATE OR REPLACE VIEW PostgreSQL
     * hanya mengizinkan penambahan di ujung daftar, dan menyisipkannya di
     * tengah menuntut DROP yang akan menjatuhkan view dependen.
     */
    private function rebuildView(bool $denganKategori = true): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_admission_summary AS
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
                   b.bed_number'
                   .($denganKategori ? ',
                   a.patient_category' : '').'
              FROM '.self::S.'.admissions a
              LEFT JOIN '.self::S.'.beds b  ON b.id = a.bed_id
              LEFT JOIN '.self::S.'.rooms r ON r.id = b.room_id');
    }
};
