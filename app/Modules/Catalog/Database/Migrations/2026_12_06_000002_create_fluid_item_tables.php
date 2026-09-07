<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master jenis cairan masuk & keluar (domain M item E).
 *
 * ARAH MELEKAT PADA JENIS CAIRANNYA, dan itu yang membuat aturannya bisa
 * ditegakkan: urine selalu keluar, infus selalu masuk, dan tidak ada
 * keadaan yang membalikkannya. Arah yang diisi terpisah setiap kali
 * mencatat membuka celah urine tercatat sebagai asupan — dan saldo yang
 * dihasilkan akan tampak wajar sambil sepenuhnya keliru. Pola yang sama
 * seperti arah transaksi kas di domain K item A.
 *
 * KHANZA MEMASANG KOLOM PER JENIS CAIRAN, sehingga hemodialisa yang
 * butuh jenis lain (sisa priming, wash out, perdarahan, muntah) melahirkan
 * tabel kedua yang isinya nyaris sama. Dengan master ini, jenis baru cukup
 * satu baris dan hemodialisa memakai tabel yang sama.
 */
return new class extends Migration
{
    private const S = 'catalog';

    public function up(): void
    {
        Schema::create(self::S . '.fluid_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('direction', 10);

            // Di mana jenis ini lazim dipakai. null berarti umum — jenis
            // hemodialisa tidak perlu muncul di layar bangsal biasa.
            $table->string('care_context', 40)->nullable();

            $table->unsignedSmallInteger('sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['care_context', 'is_active']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".fluid_items
            ADD CONSTRAINT fluid_items_direction_check
            CHECK (direction IN ('masuk','keluar'))");

        DB::statement('CREATE OR REPLACE VIEW catalog.v_fluid_item AS
            SELECT
                id      AS fluid_item_id,
                code,
                name,
                direction,
                care_context,
                sequence,
                is_active
            FROM catalog.fluid_items');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.v_fluid_item');
        Schema::dropIfExists(self::S . '.fluid_items');
    }
};
