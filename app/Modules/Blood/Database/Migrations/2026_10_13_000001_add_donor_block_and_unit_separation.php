<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit ulang konteks blood (2026-10): domain N ditandai "11 kapabilitas
 * tanpa mis-tagging" — benar soal tagging, tapi 2 kode TIDAK ada
 * kolom/layar sama sekali dan tidak terdokumentasi di mana pun sebagai
 * already-satisfied/sengaja dilewati (beda dari 4 kode BHP consumable
 * yang memang sudah didokumentasikan roles.json sebagai belum digarap):
 *
 *  - utd_cekal_darah (pencekalan pendonor): pendonor punya toggle
 *    is_active generik, tapi tidak ada mekanisme pencekalan dengan
 *    alasan & jangka waktu (mis. abis dari daerah endemis malaria,
 *    dicekal 3 bulan) — beda dari sekadar "nonaktif".
 *  - utd_pemisahan_darah (pemisahan komponen darah): StockController::
 *    collect() cuma mencatat SATU komponen per pengambilan, tidak ada
 *    operasi "pisahkan whole-blood jadi PRC+plasma+platelet" yang
 *    menghasilkan beberapa unit anak dari satu unit induk.
 *
 * utd_komponen_darah dan utd_donor TIDAK dapat perubahan — sudah cukup
 * terpenuhi lewat kolom component pada collect() dan event collect()
 * itu sendiri sebagai catatan donasi.
 */
return new class extends Migration
{
    private const S = 'blood';

    public function up(): void
    {
        Schema::table(self::S . '.donors', function (Blueprint $table) {
            $table->string('block_reason', 255)->nullable()->after('is_active');
            $table->date('blocked_until')->nullable()->comment('null = cekal permanen, terisi = cekal sementara sampai tanggal ini')->after('block_reason');
        });

        Schema::table(self::S . '.blood_units', function (Blueprint $table) {
            $table->foreignId('parent_unit_id')->nullable()->after('donor_id')
                ->constrained(self::S . '.blood_units')
                ->comment('Terisi kalau unit ini hasil pemisahan komponen dari unit whole-blood lain');
        });

        DB::statement('ALTER TABLE ' . self::S . '.blood_units DROP CONSTRAINT blood_units_status_check');
        DB::statement("ALTER TABLE " . self::S . ".blood_units ADD CONSTRAINT blood_units_status_check
            CHECK (status IN ('karantina','tersedia','ditahan','dikeluarkan','kedaluwarsa','ditolak','dipisahkan'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.blood_units DROP CONSTRAINT blood_units_status_check');
        DB::statement("ALTER TABLE " . self::S . ".blood_units ADD CONSTRAINT blood_units_status_check
            CHECK (status IN ('karantina','tersedia','ditahan','dikeluarkan','kedaluwarsa','ditolak'))");

        Schema::table(self::S . '.blood_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_unit_id');
        });

        Schema::table(self::S . '.donors', function (Blueprint $table) {
            $table->dropColumn(['block_reason', 'blocked_until']);
        });
    }
};
