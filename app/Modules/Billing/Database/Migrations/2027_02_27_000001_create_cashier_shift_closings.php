<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penutupan shift kasir (Khanza `closing_kasir`, domain U).
 *
 * NAMANYA MENJANJIKAN SESUATU YANG TABELNYA TIDAK LAKUKAN. `closing_kasir`
 * Khanza berisi tepat tiga kolom: `shift` enum('Pagi','Siang','Sore',
 * 'Malam'), `jam_masuk`, dan `jam_pulang`. Itu jadwal shift, bukan
 * penutupan. Tidak ada baris penutupan, tidak ada hitungan uang laci, tidak
 * ada selisih — jadi seorang kasir bisa mengakhiri shift dengan jumlah uang
 * berapa pun dan tidak ada apa pun yang membandingkannya dengan yang
 * tercatat sistem. Menu bernama "Closing Kasir" yang tidak menutup apa-apa
 * lebih buruk daripada tidak ada menunya: ia membuat orang mengira
 * pencocokannya sudah terjadi.
 *
 * SELISIH DIHITUNG, TIDAK DISIMPAN. Yang disimpan cuma dua angka yang
 * benar-benar diamati: jumlah uang yang DIHITUNG petugas, dan jumlah
 * pembayaran yang TERCATAT sistem pada rentang shift itu. Selisih yang
 * dibekukan jadi kolom akan salah begitu salah satu sisinya dikoreksi —
 * dan selisih kas yang salah adalah persis angka yang dipakai menuduh
 * orang. Aturan yang sama dipakai stok opname (domain D, E, F, S).
 *
 * JUMLAH TERCATAT DIBEKUKAN SAAT MENUTUP, bukan dihitung ulang saat
 * laporan dibuka. Ini pengecualian yang disengaja dari aturan "nilai
 * turunan tidak disimpan", dan alasannya sama dengan stok sistem pada
 * opname: pembayaran yang masuk terlambat, dikoreksi, atau dibatalkan
 * setelah shift ditutup akan mengubah angka pembanding secara surut, dan
 * petugas yang sudah menandatangani selisih nol tiba-tiba punya selisih
 * yang tidak pernah ia lihat. Yang dibekukan adalah APA YANG TERLIHAT SAAT
 * PENUTUPAN — itulah yang ia tanda tangani.
 *
 * SHIFT JADI BARIS, BUKAN ENUM. Empat shift dikunci di tipe kolom pada
 * Khanza; rumah sakit yang membuka shift kelima harus mengubah tipe kolom.
 * Bentuk cacat yang sama dengan `jam_diet_pasien`.
 *
 * SELISIH TIDAK MEMBLOKIR PENUTUPAN, TAPI WAJIB BERALASAN. Menahan
 * penutupan sampai selisihnya nol akan membuat petugas mengetik angka yang
 * mencocokkan alih-alih angka yang ia hitung — dan sejak itu seluruh
 * catatan kas jadi karangan yang rapi. Yang perlu adalah selisihnya
 * TERCATAT berikut penjelasannya.
 *
 * YANG SENGAJA TIDAK DICAKUP: kasir apotek dan kasir toko. Keduanya punya
 * rekap hariannya sendiri di konteks masing-masing, dan menyatukan
 * penutupannya di sini berarti satu orang menutup laci yang tidak ia pegang.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        Schema::create(self::S.'.cashier_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 40);

            $table->time('start_time');
            $table->time('end_time');

            /*
             * Shift malam menyeberang tengah malam, dan itu bukan kasus
             * langka — ia terjadi setiap hari. Tanpa penanda ini, rentang
             * 22:00–06:00 terbaca sebagai rentang kosong dan tidak satu pun
             * pembayaran masuk hitungannya.
             */
            $table->boolean('crosses_midnight')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create(self::S.'.cashier_shift_closings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('closing_number', 24)->unique();

            $table->foreignId('shift_id')->constrained(self::S.'.cashier_shifts');
            $table->date('business_date');

            $table->unsignedBigInteger('cashier_id')->nullable();
            $table->string('cashier_name', 150);

            /*
             * RENTANG SHIFT dan WAKTU PENUTUPAN adalah dua hal berbeda, dan
             * menyatukannya adalah kekeliruan yang langsung terlihat pada
             * shift malam: rentangnya 22:00 sampai 06:00 esok hari, tapi
             * penutupannya bisa dikerjakan pukul 07:00 — atau dicatat
             * belakangan pada hari kerja berikutnya. Rentang menentukan
             * pembayaran mana yang dihitung; closed_at menentukan kapan
             * orangnya menandatangani.
             */
            $table->timestampTz('window_from');
            $table->timestampTz('window_until');
            $table->timestampTz('closed_at');

            /*
             * DUA ANGKA YANG BENAR-BENAR DIAMATI, dan tidak ada yang ketiga.
             * Selisihnya dihitung dari keduanya, tidak disimpan.
             */
            $table->decimal('counted_cash', 16, 2)->comment('Uang yang dihitung petugas di laci');
            $table->decimal('recorded_cash', 16, 2)->comment('Pembayaran tunai tercatat, DIBEKUKAN saat menutup');

            // Bukan tunai ikut dicatat supaya penutupan bisa dibandingkan
            // dengan setoran bank, tapi TIDAK ikut hitungan selisih laci.
            $table->decimal('recorded_noncash', 16, 2)->default(0);

            $table->text('variance_reason')->nullable();
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['business_date', 'shift_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.cashier_shift_closings
            ADD CONSTRAINT cashier_shift_closings_amount_check
            CHECK (counted_cash >= 0 AND recorded_cash >= 0 AND recorded_noncash >= 0)');

        DB::statement('ALTER TABLE '.self::S.'.cashier_shift_closings
            ADD CONSTRAINT cashier_shift_closings_window_check CHECK (window_until > window_from)');

        /*
         * SELISIH WAJIB BERALASAN — ditegakkan di basis data, bukan hanya
         * di service. Penutupan berselisih tanpa penjelasan adalah baris
         * yang tidak bisa ditindaklanjuti siapa pun: yang membacanya sebulan
         * kemudian cuma tahu ada uang yang tidak cocok, tanpa tahu sudah
         * ditanyakan atau belum.
         */
        DB::statement('ALTER TABLE '.self::S.".cashier_shift_closings
            ADD CONSTRAINT cashier_shift_closings_variance_check
            CHECK (
                counted_cash = recorded_cash
                OR (variance_reason IS NOT NULL AND btrim(variance_reason) <> '')
            )");

        // Satu penutupan per shift per hari. Dua penutupan untuk satu shift
        // berarti salah satunya menghitung uang yang sama dua kali.
        DB::statement('CREATE UNIQUE INDEX cashier_shift_closings_unik
            ON '.self::S.'.cashier_shift_closings (business_date, shift_id, cashier_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.cashier_shift_closings');
        Schema::dropIfExists(self::S.'.cashier_shifts');
    }
};
