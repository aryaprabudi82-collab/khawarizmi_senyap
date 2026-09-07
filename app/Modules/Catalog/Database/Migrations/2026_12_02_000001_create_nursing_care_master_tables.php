<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master masalah & rencana keperawatan (domain M item B).
 *
 * Menaungi 16 kode master Khanza: master_masalah_keperawatan berikut tujuh
 * varian spesialisasinya (anak, geriatri, gigi, igd, mata, neonatus,
 * psikiatri) dan master_rencana_keperawatan dengan tujuh varian yang sama.
 *
 * DIPERIKSA LANGSUNG KE SSKEMA KHANZA, BUKAN DIKIRA-KIRA. Keenam belas
 * tabel itu berbentuk identik — kode, nama, dan (untuk rencana) kode
 * masalah induknya; yang berbeda cuma spesialisasinya. Karena itu di sini
 * jadi DUA tabel dengan kolom specialty, bukan enam belas.
 *
 * YANG PALING MENENTUKAN, DAN INI DIAMBIL DARI KHANZA SENDIRI:
 * master_rencana_keperawatan punya FOREIGN KEY ke master_masalah_keperawatan.
 * Artinya rencana keperawatan MILIK masalahnya — bukan daftar bebas. Itu
 * memang bentuk asuhan keperawatan yang benar: intervensi selalu punya
 * indikasi, dan rencana tanpa masalah adalah tindakan tanpa alasan.
 * Hierarki itu dipertahankan di sini.
 *
 * KODE STANDAR DISEDIAKAN TAPI TIDAK DIWAJIBKAN. SDKI (Standar Diagnosis
 * Keperawatan Indonesia) dan SIKI (Standar Intervensi Keperawatan
 * Indonesia) punya penomoran resmi, dan mengisinya membuat asuhan
 * keperawatan bisa dilaporkan secara nasional. Tapi mewajibkannya akan
 * menahan rumah sakit memakai rumusan yang sudah berjalan di lapangan —
 * dan asuhan yang tidak tercatat jauh lebih buruk daripada asuhan yang
 * kodenya belum standar. Aturan yang sama seperti alergi di domain L
 * item D.
 */
return new class extends Migration
{
    private const S = 'catalog';

    public function up(): void
    {
        Schema::create(self::S . '.nursing_problems', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20);
            $table->string('name', 200);

            // null berarti berlaku umum — bukan berarti belum diisi.
            $table->string('specialty', 40)->nullable()
                ->comment('anak, geriatri, gigi, igd, mata, neonatus, psikiatri; null = umum');

            $table->string('standard_code', 20)->nullable()->comment('Kode SDKI bila ada');
            $table->text('definition')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Kode unik PER SPESIALISASI: Khanza memang memakai kode yang
            // sama di master yang berbeda, dan menyatukannya tanpa
            // spesialisasi akan membuat keduanya bertabrakan.
            $table->unique(['code', 'specialty']);
            $table->index(['specialty', 'is_active']);
        });

        Schema::create(self::S . '.nursing_care_plans', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Rencana MILIK masalahnya — diambil dari FK Khanza sendiri.
            $table->foreignId('nursing_problem_id')->constrained(self::S . '.nursing_problems');

            $table->string('code', 20);
            $table->text('plan');

            $table->string('standard_code', 20)->nullable()->comment('Kode SIKI bila ada');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['nursing_problem_id', 'code']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.nursing_care_plans');
        Schema::dropIfExists(self::S . '.nursing_problems');
    }
};
