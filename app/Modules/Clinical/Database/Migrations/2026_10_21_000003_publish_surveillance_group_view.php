<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item B: kontrak baca keanggotaan kelompok surveilans.
 *
 * Diterbitkan TERPISAH dari v_encounter_diagnosis, bukan digabung lewat
 * join: satu penyakit bisa masuk beberapa kelompok sekaligus (campak itu
 * menular sekaligus PD3I), sehingga menggabungkannya akan menggandakan
 * baris diagnosis dan membuat setiap hitungan morbiditas terlalu besar —
 * kesalahan yang tidak terlihat karena angkanya tetap "masuk akal".
 *
 * Reporting memakainya sebagai penyaring keanggotaan, bukan sebagai
 * tabel yang ikut dijumlahkan.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_diagnosis_surveillance_group AS
            SELECT code, "group", note
              FROM ' . self::S . '.diagnosis_surveillance_groups');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_diagnosis_surveillance_group');
    }
};
