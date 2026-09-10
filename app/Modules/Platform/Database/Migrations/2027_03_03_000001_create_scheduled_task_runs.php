<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan kapan tiap perawatan terjadwal terakhir benar-benar berjalan.
 *
 * MENGAPA INI ADA. Seluruh perawatan otomatis sistem ini menggantung pada
 * SATU baris cron di server aplikasi:
 *
 *     * * * * * cd /path/simrs && php artisan schedule:run
 *
 * Kalau baris itu tidak dipasang — atau dipasang lalu hilang saat server
 * dipindah, atau mati karena kredensialnya kedaluwarsa — TIDAK ADA GALAT
 * APA PUN yang muncul. Aplikasi tetap melayani pasien seperti biasa. Yang
 * berhenti cuma perawatannya, dan akibatnya baru terasa berbulan-bulan
 * kemudian sebagai partisi yang habis dan sistem yang melambat tanpa sebab
 * yang bisa ditunjuk.
 *
 * Itu bentuk kegagalan yang paling buruk: senyap, tertunda, dan mahal
 * diperbaiki. Dokumentasi tidak menutupnya — yang memasang server dua tahun
 * lagi bukan orang yang membaca dokumen hari ini.
 *
 * Dengan tabel ini, "cron tidak berjalan" terdeteksi dalam DUA HARI lewat
 * `siap:periksa`, bukan dua tahun lewat keluhan pengguna.
 *
 * SENGAJA BUKAN TABEL LOG. Yang disimpan hanya BARIS TERAKHIR per tugas,
 * bukan riwayat tiap jalan. Riwayat perawatan yang berjalan tiap hari
 * selama sepuluh tahun adalah tiga ribu baris yang tidak pernah dibaca
 * siapa pun — dan pertanyaannya memang cuma satu: kapan terakhir jalan.
 */
return new class extends Migration
{
    private const S = 'platform';

    public function up(): void
    {
        Schema::create(self::S.'.scheduled_task_runs', function (Blueprint $table) {
            $table->string('task', 60)->primary();

            $table->timestampTz('last_run_at');

            /*
             * Hasil ringkas jalan terakhir, untuk dibaca manusia yang sedang
             * menelusuri. Bukan log: ia ditimpa tiap kali.
             */
            $table->string('summary', 250)->nullable();

            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.scheduled_task_runs');
    }
};
