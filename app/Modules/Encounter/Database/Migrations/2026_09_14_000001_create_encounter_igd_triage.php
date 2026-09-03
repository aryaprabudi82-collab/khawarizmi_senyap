<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * igd (Khanza domain A, kelas DlgIGD) — tercatat context=encounter tanpa
 * penanda paket Java lain, tetap dibangun di sini.
 *
 * Registrasi IGD memakai jalur registrasi biasa yang sudah ada
 * (RegistrationService::register, unit 'IGD' yang sudah diseed sejak awal
 * dengan daily_quota null — sengaja tak terbatas, ER tidak boleh menolak
 * pasien karena "kuota penuh") dengan care_type='igd' baru — bukan
 * mendaftarkan lewat jalur terpisah. Validasi jadwal praktik yang dibangun
 * di booking_registrasi/booking_periksa otomatis tidak berlaku (registrasi
 * IGD selalu untuk hari ini, cuma tanggal MENDATANG yang diperiksa
 * terhadap jadwal).
 *
 * Yang genuinely baru cuma TRIASE: level warna standar IGD Indonesia
 * (merah/kuning/hijau/hitam) dan keluhan utama, dicatat begitu pasien
 * masuk — inilah yang membedakan alur IGD dari ralan biasa: pasien
 * dilayani berurutan berdasar keparahan, bukan nomor antrean. Satu baris
 * per registrasi, BOLEH diubah (retriase saat kondisi berubah) — sengaja
 * tidak berjenjang seperti dpjp_history/employee_position_history, karena
 * retriase adalah koreksi status yang sama, bukan periode tanggung jawab
 * berbeda yang perlu jejak "siapa bertanggung jawab kapan".
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.registrations DROP CONSTRAINT registrations_care_type_check');
        DB::statement("ALTER TABLE " . self::S . ".registrations ADD CONSTRAINT registrations_care_type_check
            CHECK (care_type IN ('ralan','ranap','igd'))");

        Schema::create(self::S . '.igd_triages', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id')->unique();
            $table->string('triage_level', 10)->comment('merah, kuning, hijau, hitam');
            $table->text('chief_complaint');

            $table->unsignedBigInteger('triaged_by')->nullable();
            $table->string('triaged_by_name', 150)->nullable();
            $table->timestampTz('triaged_at');

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".igd_triages ADD CONSTRAINT igd_triages_level_check
            CHECK (triage_level IN ('merah','kuning','hijau','hitam'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.igd_triages');

        DB::statement('ALTER TABLE ' . self::S . '.registrations DROP CONSTRAINT registrations_care_type_check');
        DB::statement("ALTER TABLE " . self::S . ".registrations ADD CONSTRAINT registrations_care_type_check
            CHECK (care_type IN ('ralan','ranap'))");
    }
};
