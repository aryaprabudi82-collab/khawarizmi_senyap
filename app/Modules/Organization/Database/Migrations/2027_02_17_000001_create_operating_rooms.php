<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master ruang operasi (Khanza `ruang_ok`, domain U).
 *
 * CACATNYA BUKAN DI KHANZA SAJA — DI SINI JUGA, DAN SUDAH MERUSAK SESUATU.
 * Khanza punya tabel `ruang_ok` (kd_ruang_ok + nm_ruang_ok) tapi menunya
 * berdiri sendiri tanpa yang memakainya. Di sistem ini keadaannya lebih
 * buruk: nama ruang operasi disimpan sebagai TEKS BEBAS di DUA konteks —
 * `clinical.operations.operating_room` dan
 * `encounter.operation_bookings.operating_room` — jadi ruang yang sama
 * diketik dua kali oleh dua orang yang berbeda.
 *
 * Akibatnya sudah nyata, bukan hipotetis: `StatutoryReportService`
 * mengelompokkan laporan RL berdasarkan teks itu. "OK 1" dan "OK1" memecah
 * satu ruang jadi DUA baris pada laporan wajib yang dikirim ke Kemenkes,
 * dan tidak ada satu pun galat yang muncul — angkanya tetap tampak wajar,
 * hanya saja utilisasi ruangnya terbelah.
 *
 * RUMAHNYA DI `organization`, BUKAN DI SALAH SATU PEMAKAINYA. Dua konteks
 * membacanya; menaruhnya di clinical berarti encounter harus menyeberang
 * batas, dan menaruhnya di keduanya mengembalikan persis masalah yang
 * hendak ditutup. Diterbitkan sebagai view supaya keduanya bisa membaca
 * tanpa mengimpor modelnya.
 *
 * LAHIR KOSONG. Daftar ruang operasi RSP UI adalah kenyataan fisik gedung
 * yang tidak bisa ditebak dari luar — berapa ruangnya, bagaimana
 * penomorannya, dan mana yang masih dipakai. Menebaknya berarti menyediakan
 * pilihan yang tidak ada di gedungnya, lalu jadwal operasi menunjuk ruang
 * yang tidak pernah dibangun.
 */
return new class extends Migration
{
    private const S = 'organization';

    public function up(): void
    {
        Schema::create(self::S.'.operating_rooms', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 60);

            // Unit pemilik. Boleh kosong: sebagian rumah sakit menempatkan
            // seluruh kamar operasi di bawah satu instalasi bedah sentral
            // dan tidak membaginya per unit sama sekali.
            $table->unsignedBigInteger('unit_id')->nullable();

            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('is_active');
        });

        DB::statement('ALTER TABLE '.self::S.'.operating_rooms
            ADD CONSTRAINT operating_rooms_unit_fk
            FOREIGN KEY (unit_id) REFERENCES '.self::S.'.units (id)');

        /*
         * Diterbitkan supaya clinical dan encounter bisa memvalidasi
         * pilihan ruang tanpa mengimpor model konteks ini — kontraknya
         * kode dan nama, bukan bentuk tabelnya.
         */
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_operating_room AS
            SELECT r.id,
                   r.code,
                   r.name,
                   r.unit_id,
                   u.name AS unit_name,
                   r.is_active
              FROM '.self::S.'.operating_rooms r
              LEFT JOIN '.self::S.'.units u ON u.id = r.unit_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_operating_room');
        Schema::dropIfExists(self::S.'.operating_rooms');
    }
};
