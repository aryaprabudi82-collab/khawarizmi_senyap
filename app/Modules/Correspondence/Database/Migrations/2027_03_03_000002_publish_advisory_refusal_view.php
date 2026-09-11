<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan pertama konteks correspondence: penolakan anjuran medis
 * (domain J — laporan_tahunan_penolakan_anjuran_medis).
 *
 * DITEMUKAN SAAT VERIFIKASI DOMAIN J. Kodenya tidak pernah dibuatkan
 * angka, padahal pencatatannya sudah ada sejak domain P:
 * correspondence.patient_consents menyimpan keputusan setuju/menolak
 * berikut jenis dan waktunya.
 *
 * YANG DIHITUNG SEBAGAI PENOLAKAN ADALAH `decision = 'menolak'`, APA PUN
 * JENIS SURATNYA. Menghitung hanya consent_type = 'penolakan-anjuran-medis'
 * akan melewatkan pasien yang menolak tindakan yang ditawarkan
 * (consent_type 'tindakan', decision 'menolak') dan yang pulang atas
 * permintaan sendiri — dua bentuk penolakan anjuran medis yang paling
 * sering terjadi. Laporan tahunan yang angkanya terlalu kecil akan dibaca
 * sebagai kabar baik.
 *
 * SURAT YANG DIBATALKAN DIKECUALIKAN: status 'dibatalkan' berarti
 * dokumennya ditarik, dan penolakan yang dokumennya ditarik bukan
 * penolakan yang bisa dipertanggungjawabkan.
 *
 * URAIAN TINDAKAN, NAMA SAKSI, DAN NAMA PASIEN SENGAJA TIDAK IKUT.
 * Laporan tahunan menjawab berapa banyak dan jenis apa; membawa uraian
 * keputusan medis pasien keluar dari konteksnya berarti membuka isi
 * percakapan dokter-pasien kepada modul pelaporan yang tidak
 * membutuhkannya. patient_id ikut hanya supaya pasien yang menolak
 * berkali-kali tidak terhitung sebagai beberapa orang.
 */
return new class extends Migration
{
    private const S = 'correspondence';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_advisory_refusal AS
            SELECT c.id,
                   c.consent_type,
                   c.decision,
                   c.patient_id,
                   c.registration_id,
                   c.signed_at
              FROM '.self::S.'.patient_consents c
             WHERE c.decision = \'menolak\'
               AND c.status <> \'dibatalkan\'');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_advisory_refusal');
    }
};
