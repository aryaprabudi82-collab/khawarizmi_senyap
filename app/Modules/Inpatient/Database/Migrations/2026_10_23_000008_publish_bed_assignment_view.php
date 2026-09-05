<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item D: rentang penempatan bed diterbitkan untuk hitungan BOR.
 *
 * BOR butuh HARI-RAWAT, dan hari-rawat hanya benar kalau dihitung dari
 * penempatan bed yang sungguh terjadi — bukan dari jumlah admisi. Pasien
 * yang pindah kamar di tengah rawat punya dua penempatan tapi tetap satu
 * pasien; menghitungnya dari admisi akan melewatkan perpindahannya,
 * menghitungnya dari admisi x kamar akan menggandakannya.
 *
 * Yang dipaparkan hanya rentang waktunya, bukan identitas pasien:
 * laporan efisiensi tempat tidur tidak perlu tahu siapa yang menempati.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_bed_assignment AS
            SELECT a.id, a.admission_id, a.bed_id, a.assigned_at, a.released_at
              FROM ' . self::S . '.bed_assignments a');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_bed_assignment');
    }
};
