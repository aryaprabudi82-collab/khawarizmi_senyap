<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain J item D: lama operasi.
 *
 * clinical.operations hanya menyimpan performed_at — satu titik waktu,
 * yang cukup untuk menagih dan untuk RL 3.6 (menghitung tindakan) tapi
 * mustahil dipakai menghitung durasi. Ditambah started_at/finished_at.
 *
 * performed_at TIDAK diganti maknanya: ia tetap penanda kapan operasi
 * terjadi, dipakai penagihan dan RL 3.6 yang sudah berjalan. Mengubah
 * artinya akan diam-diam menggeser angka laporan yang sudah benar.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::table(self::S . '.operations', function (Blueprint $table) {
            $table->timestampTz('started_at')->nullable()->after('performed_at')
                ->comment('Saat insisi/operasi dimulai');
            $table->timestampTz('finished_at')->nullable()->after('started_at')
                ->comment('Saat operasi selesai; selisih dari started_at = lama operasi');
        });
    }

    public function down(): void
    {
        Schema::table(self::S . '.operations', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'finished_at']);
        });
    }
};
