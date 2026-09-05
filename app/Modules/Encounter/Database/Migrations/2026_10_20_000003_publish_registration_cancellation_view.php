<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item A: kontrak baca pembatalan kunjungan untuk reporting.
 *
 * v_registration_summary sengaja MEMBUANG kunjungan berstatus batal, dan
 * itu invarian yang tidak boleh diubah: billing, pharmacy, dan clinical
 * membaca kontrak yang sama untuk menentukan kunjungan mana yang boleh
 * ditindaklanjuti. Kalau kunjungan batal ikut muncul di sana, kunjungan
 * yang sudah dibatalkan bisa ditagih atau diresepkan — kesalahan yang
 * jauh lebih berbahaya daripada satu laporan yang kosong.
 *
 * Karena itu pembatalan diterbitkan sebagai kontrak TERPISAH, khusus
 * untuk laporan (kode pembatalan_periksa_dokter). Ketahuan saat menulis
 * pengujian item A: laporan pembatalannya selalu nol, dan penyebabnya
 * bukan salah query melainkan datanya memang tidak terjangkau.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_registration_cancellation AS
            SELECT id            AS registration_id,
                   registration_number,
                   patient_id,
                   patient_mrn,
                   patient_name,
                   unit_id,
                   unit_name,
                   practitioner_id,
                   practitioner_name,
                   payer_name,
                   service_date,
                   registered_at,
                   care_type
              FROM ' . self::S . ".registrations
             WHERE status = 'batal'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_registration_cancellation');
    }
};
