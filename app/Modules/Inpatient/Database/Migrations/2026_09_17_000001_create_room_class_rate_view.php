<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dibaca finance untuk perkiraan_biaya_ranap (lihat migrasi finance yang
 * sama tanggalnya) — perkiraan biaya ranap butuh tarif kamar per kelas
 * tanpa finance menyentuh inpatient.rooms langsung.
 *
 * Rata-rata, bukan satu angka pasti: daily_rate memang bisa berbeda antar
 * kamar dalam kelas yang sama (lihat catatan di migrasi rooms), jadi
 * dirata-rata per kelas supaya "perkiraan" tetap jujur namanya - bukan
 * tarif final. Kamar nonaktif tidak ikut dihitung.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement("CREATE VIEW " . self::S . ".v_room_class_rate AS
            SELECT room_class,
                   ROUND(AVG(daily_rate), 2) AS avg_daily_rate,
                   COUNT(*)                  AS active_room_count
            FROM " . self::S . ".rooms
            WHERE is_active
            GROUP BY room_class");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_room_class_rate');
    }
};
