<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak sesi hemodialisa (domain O item D).
 *
 * Menaungi grafik_harian_hemodialisa, _bulanan, dan _tahunan.
 *
 * SESI DIALISIS BARU ADA SEJAK DOMAIN M ITEM P, jadi ketiga grafik ini
 * memang tidak mungkin dibuat sebelumnya — bukan terlewat, melainkan
 * datanya belum ada.
 *
 * YANG DITERBITKAN HANYA IDENTITAS SESI DAN JENIS AKSESNYA. Serologi
 * pasien, berat kering, target ultrafiltrasi, dan penyulit selama sesi
 * TIDAK ikut: grafik menanyakan berapa banyak sesi berjalan, bukan
 * keadaan klinis tiap pasien, dan menerbitkan keadaan klinis untuk
 * keperluan menghitung batang membuka data yang tidak dibutuhkan
 * siapa pun yang membaca grafiknya.
 *
 * SESI YANG DIBATALKAN DIKECUALIKAN dari kontrak — pola yang sama
 * dengan kunjungan batal sejak domain J item A: sesi batal bukan sesi.
 * Yang dihentikan di tengah TETAP IKUT, karena itu sesi yang benar-
 * benar berjalan dan pasiennya benar-benar didialisis.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('CREATE VIEW '.self::S.'.v_dialysis_session AS
            SELECT id,
                   registration_id,
                   patient_id,
                   started_at,
                   ended_at,
                   access_type,
                   machine_code,
                   status,
                   practitioner_name
              FROM '.self::S.".dialysis_sessions
             WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_dialysis_session');
    }
};
