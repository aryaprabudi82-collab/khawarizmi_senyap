<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * jadwal_praktek (Khanza domain A, kelas DlgJadwal) — tercatat context=
 * encounter di platform.permissions, tapi paket Java-nya "kepegawaian"
 * (lihat Khanza_Functional_Dependency_Map.xlsx sheet2) dan tabel terkaitnya
 * di Khanza (jadwal_pegawai, jadwal_tambahan) menyiratkan jadwal dokter di
 * Khanza adalah SATU KASUS dari sistem jadwal-shift pegawai yang lebih umum.
 *
 * SIMRS Mandiri sengaja TIDAK mengikuti itu — organization.practitioners
 * sudah dari awal dipisah dari hr.employees justru supaya penjadwalan
 * klinis (siapa boleh dipilih jadi DPJP/menerima pasien) tidak bergantung
 * pada modul kepegawaian: praktisi tamu/paruh waktu bisa saja tidak punya
 * baris di hr.employees sama sekali. Menaruh jadwal praktik di hr berarti
 * encounter (yang butuh tahu "dokter mana buka praktik jam berapa hari
 * ini") harus membaca DUA konteks berbeda untuk satu pertanyaan yang
 * sebetulnya satu. Jadi jadwal praktik dibangun di sini, memperluas
 * organization.practitioners yang sudah ada — bukan hr.
 *
 * Wave 1 ini murni data jadwal (CRUD admin data master) — BELUM dipakai
 * mengubah OrganizationDirectory::practitionerIsServing()/
 * practitionersServingOn(), yang masih berbasis active_from/active_until
 * saja seperti sebelumnya, supaya seluruh alur registrasi/asesmen yang
 * sudah teruji tidak berubah perilaku. Jadwal ini akan dikonsumsi saat
 * booking_registrasi/booking_periksa dibangun (agenda domain A berikutnya)
 * untuk menghitung slot yang tersedia.
 */
return new class extends Migration
{
    private const S = 'organization';

    public function up(): void
    {
        Schema::create(self::S . '.practice_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('practitioner_id')->constrained(self::S . '.practitioners')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained(self::S . '.units');

            $table->unsignedTinyInteger('day_of_week')->comment('1=Senin .. 7=Minggu, ISO-8601');
            $table->time('start_time');
            $table->time('end_time');

            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['practitioner_id', 'day_of_week']);
            $table->index(['unit_id', 'day_of_week']);
        });

        DB::statement("ALTER TABLE " . self::S . ".practice_schedules ADD CONSTRAINT practice_schedules_day_check
            CHECK (day_of_week BETWEEN 1 AND 7)");
        DB::statement("ALTER TABLE " . self::S . ".practice_schedules ADD CONSTRAINT practice_schedules_time_order_check
            CHECK (end_time > start_time)");

        // Jadwal yang identik (dokter+unit+hari+jam mulai) tidak boleh
        // dobel — bukan jaminan anti-tumpang-tindih penuh (butuh exclusion
        // constraint berbasis range, di luar cakupan Wave 1 ini), tapi
        // menahan kesalahan entri paling umum: submit ganda.
        DB::statement('CREATE UNIQUE INDEX practice_schedules_no_duplicate
            ON ' . self::S . '.practice_schedules (practitioner_id, unit_id, day_of_week, start_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.practice_schedules');
    }
};
