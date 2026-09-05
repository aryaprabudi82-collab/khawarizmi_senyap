<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item C: kontrak baca ketersediaan tempat tidur untuk RL 1.3.
 *
 * RL 1.3 melaporkan jumlah tempat tidur per kelas perawatan. Yang
 * dilaporkan adalah kapasitas terpasang, jadi bed pada kamar nonaktif
 * tidak dihitung — kamar yang ditutup bukan kapasitas yang tersedia.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_bed_availability AS
            SELECT r.room_class,
                   b.status,
                   count(*) AS jumlah
              FROM ' . self::S . '.beds b
              JOIN ' . self::S . '.rooms r ON r.id = b.room_id
             WHERE r.is_active = true
          GROUP BY r.room_class, b.status');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_bed_availability');
    }
};
