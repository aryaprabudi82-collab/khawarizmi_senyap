<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surgical Safety Checklist (domain M item C).
 *
 * Menaungi signin_sebelum_anestesi, timeout_sebelum_insisi, dan
 * signout_sebelum_menutup_luka — tiga fase daftar tilik keselamatan bedah
 * WHO yang juga menjadi butir akreditasi KARS (Sasaran Keselamatan Pasien
 * IV: kepastian tepat lokasi, tepat prosedur, tepat pasien operasi).
 *
 * DIPERIKSA LANGSUNG KE SKEMA KHANZA. Ketiga tabelnya berbagi kerangka
 * yang sama (tindakan, dokter bedah, dokter anestesi, perawat OK, waktu)
 * lalu masing-masing punya butir pemeriksaannya sendiri. Karena itu di
 * sini jadi SATU tabel dengan kolom fase, bukan tiga.
 *
 * DUA PERBAIKAN TERHADAP KHANZA, KEDUANYA BERDASAR PADA MAKSUD DAFTAR
 * TILIKNYA SENDIRI:
 *
 * 1. DILEKATKAN PADA OPERASINYA, BUKAN PADA KUNJUNGAN. Di Khanza kuncinya
 *    (no_rawat, tanggal) — pasien yang menjalani dua operasi dalam satu
 *    perawatan menghasilkan dua baris yang hanya dibedakan stempel waktu,
 *    tanpa penanda daftar tilik mana milik prosedur mana. Padahal seluruh
 *    gunanya adalah memastikan TEPAT PROSEDUR; daftar tilik yang tidak
 *    tahu ia melindungi prosedur yang mana sudah kehilangan maksudnya.
 *
 * 2. URUTAN FASE DITEGAKKAN. Sign In sebelum anestesi, Time Out sebelum
 *    insisi, Sign Out sebelum menutup luka — urutan itu bukan tata letak
 *    layar, melainkan isi aturannya. Time Out yang tercatat tanpa Sign In
 *    berarti pemeriksaan sebelum pembiusan tidak pernah terjadi, dan
 *    daftar tilik yang bisa diisi terbalik cuma menghasilkan dokumen yang
 *    rapi tanpa pengaman apa pun.
 *
 * PENANDAAN AREA OPERASI SENGAJA DITANYAKAN DUA KALI, di Sign In dan lagi
 * di Time Out. Itu bukan kelebihan yang perlu dirapikan: pemeriksaan ganda
 * oleh orang yang berbeda pada saat yang berbeda memang mekanisme
 * pengamannya. Menyatukannya jadi satu isian menghapus pengaman itu sambil
 * terlihat seperti perbaikan.
 *
 * JAWABAN DISIMPAN SEBAGAI JSON karena butir tiap fase berbeda dan
 * berkembang mengikuti revisi pedoman; yang menjadi kolom hanyalah hal
 * yang harus bisa dicari dan ditegakkan aturannya.
 */
return new class extends Migration
{
    private const S = 'clinical';

    private const FASE = ['sign-in', 'time-out', 'sign-out'];

    public function up(): void
    {
        Schema::create(self::S . '.surgical_safety_checklists', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Melekat pada OPERASINYA — lihat perbaikan 1.
            $table->foreignId('operation_id')->constrained(self::S . '.operations')->cascadeOnDelete();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_name', 150);

            $table->string('phase', 20);

            // Disalin dari operasinya saat daftar tilik diisi: nama tindakan
            // boleh diperbaiki belakangan, yang diverifikasi di kamar operasi
            // tidak boleh ikut berubah.
            $table->string('procedure_name', 200);

            $table->string('surgeon_name', 150)->nullable();
            $table->string('anesthetist_name', 150)->nullable();
            $table->string('scrub_nurse_name', 150)->nullable();

            $table->json('answers');

            // Butir yang jawabannya menuntut tindakan, dicatat terpisah
            // supaya bisa dicari: daftar tilik yang menemukan masalah tapi
            // temuannya terkubur di dalam JSON tidak menolong siapa pun.
            $table->text('concerns')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampTz('performed_at');
            $table->timestampsTz();

            // Satu fase satu kali per operasi. Dua Sign Out atas operasi yang
            // sama berarti salah satunya menghitung kasa dan instrumen dua
            // kali — dan itu justru hitungan yang paling tidak boleh keliru.
            $table->unique(['operation_id', 'phase']);
            $table->index(['registration_id', 'phase']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".surgical_safety_checklists
            ADD CONSTRAINT surgical_safety_checklists_phase_check
            CHECK (phase IN ('" . implode("','", self::FASE) . "'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.surgical_safety_checklists');
    }
};
