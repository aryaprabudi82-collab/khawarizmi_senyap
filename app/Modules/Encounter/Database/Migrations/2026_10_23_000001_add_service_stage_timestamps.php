<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain J item D: stempel waktu tiap tahap pelayanan rawat jalan.
 *
 * Tabel registrations sejak awal punya siklus status terdaftar -> dipanggil
 * -> dilayani -> selesai, tapi TIDAK ADA satu pun kode yang menaikkannya:
 * kunjungan tidak pernah beranjak dari 'terdaftar'. Jadi yang kurang bukan
 * sekadar kolom waktu, melainkan pencatatan tahapannya sendiri.
 *
 * Ini dibangun lebih dulu karena laporan waktu tunggu tidak bisa dikarang
 * dari satu stempel waktu. Waktu tunggu rawat jalan adalah indikator SPM
 * Kemenkes (standarnya <= 60 menit), dan angka yang ditebak pada indikator
 * mutu sama berbahayanya dengan angka yang ditebak pada laporan RL.
 *
 * Tiap kolom mencatat kapan tahapnya DICAPAI, bukan berapa lamanya —
 * durasi selalu dihitung ulang dari selisihnya. Menyimpan durasi berarti
 * menyimpan angka yang bisa basi kalau salah satu stempelnya diperbaiki.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        Schema::table(self::S . '.registrations', function (Blueprint $table) {
            $table->timestampTz('called_at')->nullable()->after('registered_at')
                ->comment('Saat pasien dipanggil ke ruang periksa; selisih dari registered_at = waktu tunggu SPM');
            $table->timestampTz('served_at')->nullable()->after('called_at')
                ->comment('Saat pelayanan mulai diberikan');
            $table->timestampTz('finished_at')->nullable()->after('served_at')
                ->comment('Saat pelayanan selesai; selisih dari called_at = lama pelayanan');
        });
    }

    public function down(): void
    {
        Schema::table(self::S . '.registrations', function (Blueprint $table) {
            $table->dropColumn(['called_at', 'served_at', 'finished_at']);
        });
    }
};
