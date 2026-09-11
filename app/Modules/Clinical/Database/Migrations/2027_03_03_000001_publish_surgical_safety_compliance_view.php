<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan: kepatuhan daftar tilik keselamatan bedah (domain J).
 *
 * DITEMUKAN SAAT VERIFIKASI DOMAIN J. Layar Penunjang & Gizi masih
 * menyatakan "ceklis keselamatan bedah WHO belum dicatat sama sekali"
 * sebagai alasan kepatuhan_kelengkapan_keselamatan_bedah tidak dibuatkan
 * angka. Kalimat itu benar saat ditulis pada domain J, dan berhenti benar
 * pada domain M item C — clinical.surgical_safety_checklists dibangun
 * 2026-12-03 berikut penegakan urutan fasenya.
 *
 * Pernyataan "belum ada datanya" yang basi lebih buruk daripada tidak ada
 * pernyataan sama sekali: pembacanya berhenti mencari, dan indikator
 * akreditasi yang sebenarnya bisa dihitung tetap tidak dihitung.
 *
 * OPERASI TANPA DAFTAR TILIK IKUT TERHITUNG, SEBAGAI TIDAK PATUH. Ini
 * satu-satunya keputusan penting di view ini, dan arahnya mudah dibalik
 * tanpa terlihat salah: JOIN biasa hanya memunculkan operasi yang PUNYA
 * daftar tilik, sehingga kepatuhan selalu mendekati 100% justru di rumah
 * sakit yang paling jarang mengisinya. LEFT JOIN dari operations membuat
 * yang tidak pernah diisi tampak sebagai nol fase — yang memang
 * kenyataannya, dan memang yang dicari indikator ini.
 *
 * ISI JAWABAN TIDAK IKUT DITERBITKAN. Yang dibutuhkan reporting cuma
 * berapa fase tercatat dan berapa yang menyimpan temuan; jawaban butir per
 * butir adalah isi rekam medis, dan kontrak yang membawanya keluar akan
 * membuat konteks lain bisa membacanya tanpa alasan.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_surgical_safety_compliance AS
            SELECT o.id                AS operation_id,
                   o.performed_at,
                   o.operating_room,
                   o.service_name,
                   count(c.id) FILTER (WHERE c.phase = \'sign-in\')  AS sign_in,
                   count(c.id) FILTER (WHERE c.phase = \'time-out\') AS time_out,
                   count(c.id) FILTER (WHERE c.phase = \'sign-out\') AS sign_out,
                   count(c.id)                                       AS fase_tercatat,
                   count(c.id) FILTER (
                       WHERE c.concerns IS NOT NULL AND btrim(c.concerns) <> \'\'
                   )                                                 AS fase_bertemuan
              FROM '.self::S.'.operations o
              LEFT JOIN '.self::S.'.surgical_safety_checklists c ON c.operation_id = o.id
             GROUP BY o.id, o.performed_at, o.operating_room, o.service_name');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_surgical_safety_compliance');
    }
};
