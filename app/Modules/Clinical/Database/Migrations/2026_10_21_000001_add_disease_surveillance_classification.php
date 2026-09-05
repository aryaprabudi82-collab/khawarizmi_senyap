<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain J item B: klasifikasi penyakit untuk laporan surveilans.
 *
 * Laporan penyakit menular/tidak menular, PD3I & AFP, dan data TB (SITT)
 * tidak bisa disusun dari bab ICD-10 saja. TB memang ada di Bab I, tapi
 * pneumonia ada di Bab X padahal menular; dan PD3I sama sekali bukan
 * turunan bab — ia daftar spesifik yang ditetapkan Kemenkes.
 *
 * Bentuknya mengikuti jawaban Khanza, dengan satu penyimpangan yang
 * disengaja:
 *
 *  - SIFAT PENULARAN jadi kolom pada kode diagnosis, cermin
 *    penyakit.status enum('Menular','Tidak Menular') Khanza. Sifat itu
 *    memang melekat pada penyakitnya, bukan pada program pelaporan.
 *
 *  - KEANGGOTAAN PROGRAM SURVEILANS masuk SATU tabel dengan penanda
 *    kelompok, bukan satu tabel per program seperti penyakit_pd3i.
 *    Alasannya terbaca di skema Khanza sendiri: tepat setelah
 *    penyakit_pd3i ada perawatan_corona — tiap program baru memaksa tabel
 *    dan migrasi baru. Dengan satu tabel, menambah program cukup
 *    menambah baris data. Satu penyakit juga bisa masuk beberapa kelompok
 *    sekaligus (campak itu menular sekaligus PD3I), yang tidak terwakili
 *    kalau tiap program berdiri sendiri.
 *
 * Nilai bawaan 'tidak-diketahui' dipilih dengan sadar: kamus ICD-10 resmi
 * belum diimpor (seeder baru memuat 16 kode contoh), dan menebak sifat
 * penularan ribuan kode secara otomatis akan menghasilkan laporan
 * surveilans yang tampak lengkap padahal isinya karangan. Lebih baik
 * laporannya jujur menunjukkan berapa yang belum diklasifikasi.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::table(self::S . '.diagnosis_codes', function (Blueprint $table) {
            $table->string('transmission', 20)->default('tidak-diketahui')
                ->comment('menular, tidak-menular, tidak-diketahui — padanan penyakit.status Khanza');
        });

        DB::statement('ALTER TABLE ' . self::S . ".diagnosis_codes ADD CONSTRAINT diagnosis_codes_transmission_check
            CHECK (transmission IN ('menular','tidak-menular','tidak-diketahui'))");

        Schema::create(self::S . '.diagnosis_surveillance_groups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->comment('Kode diagnosis, merujuk diagnosis_codes.code');
            $table->string('group', 30)->comment('pd3i, afp, tb-sitt, ptm-prioritas, dan program berikutnya');
            $table->string('note', 255)->nullable();

            $table->timestampsTz();

            $table->unique(['code', 'group']);
            $table->index('group');

            $table->foreign('code')->references('code')->on(self::S . '.diagnosis_codes')->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.diagnosis_surveillance_groups');

        DB::statement('ALTER TABLE ' . self::S . '.diagnosis_codes DROP CONSTRAINT diagnosis_codes_transmission_check');

        Schema::table(self::S . '.diagnosis_codes', function (Blueprint $table) {
            $table->dropColumn('transmission');
        });
    }
};
