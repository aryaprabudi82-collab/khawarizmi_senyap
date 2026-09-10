<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waktu makan pasien (Khanza `jam_diet_pasien`, domain U).
 *
 * SLOT WAKTU MAKAN ADALAH BARIS, BUKAN ENUM. `jam_diet_pasien` Khanza
 * mengunci slotnya di dalam tipe kolom: enum('Pagi','Pagi2','Pagi3',
 * 'Siang','Siang2',...,'Malam3') — dua belas slot yang ditetapkan saat
 * tabel dibuat. Menambah slot ketiga belas berarti mengubah tipe kolom,
 * yaitu mengunci tabel; dan "Pagi2" bukan nama yang berarti apa pun bagi
 * petugas gizi, ia cuma nomor urut yang bocor ke dalam data.
 *
 * Di sini slotnya baris berkode dan bernama sendiri — "snack pagi",
 * "makan siang", "snack sore" — dengan urutan yang bisa disusun ulang
 * tanpa menyentuh skema.
 *
 * LAHIR KOSONG. Jam makan RSP UI adalah keputusan instalasi gizi yang
 * terikat jadwal produksi dapur dan jadwal obat; menebaknya berarti
 * menerbitkan jadwal makan resmi yang tidak pernah disepakati siapa pun,
 * lalu memakainya menjadwalkan pengantaran ke bangsal.
 *
 * RUMAHNYA DI DAPUR, BUKAN DI RAWAT INAP. Yang menetapkan jam makan adalah
 * yang harus memasaknya tepat waktu. Order diet (inpatient) menunjuk slot
 * ini lewat view yang diterbitkan, bukan sebaliknya.
 */
return new class extends Migration
{
    private const S = 'kitchen';

    public function up(): void
    {
        Schema::create(self::S.'.meal_times', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 60);

            /*
             * Jam BOLEH kosong, dan itu bukan kelalaian: slot yang sudah
             * disepakati ada tapi jamnya belum ditetapkan berbeda dari slot
             * yang jamnya tengah malam. Kalau dipaksa terisi, orang akan
             * mengetik 00:00 dan dapur menjadwalkan pengantaran tengah malam.
             */
            $table->time('serve_at')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['is_active', 'position']);
        });

        /*
         * Diterbitkan supaya inpatient bisa menautkan order diet ke slot
         * makan tanpa mengimpor model konteks dapur.
         */
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_meal_time AS
            SELECT m.id, m.code, m.name, m.serve_at, m.position, m.is_active
              FROM '.self::S.'.meal_times m');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_meal_time');
        Schema::dropIfExists(self::S.'.meal_times');
    }
};
