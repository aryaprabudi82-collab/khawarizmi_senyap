<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * booking_mcu_perusahaan (Khanza domain A, kelas DlgBookingMCUPerusahaan,
 * paket Java "permintaan") — pemesanan medical check-up massal oleh
 * perusahaan, sebelum karyawannya benar-benar terdaftar sebagai pasien.
 * Genuinely encounter, sama seperti booking_registrasi/booking_periksa.
 *
 * Bukan employee-roster: Wave 1 ini cuma catatan pemesanan (nama
 * perusahaan, tanggal, jumlah karyawan, paket pemeriksaan bebas teks).
 * Registrasi tiap karyawan pada hari-H tetap lewat encounter.registrations
 * biasa — tidak ada tautan formal antara satu baris booking ini dengan
 * registrasi individual yang dihasilkannya, sama seperti booking_registrasi/
 * booking_periksa juga tidak membuat baris registrations sampai pasiennya
 * benar-benar datang (lihat migrasi booking_registrasi/booking_periksa).
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        Schema::create(self::S . '.corporate_mcu_bookings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('booking_number', 24)->unique();

            $table->string('company_name', 150);
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_phone', 30)->nullable();

            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_name', 150)->nullable();

            $table->date('scheduled_date');
            $table->unsignedInteger('employee_count');
            $table->string('package_description', 500)->nullable();

            $table->string('status', 20)->default('dijadwalkan');
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('booked_by')->nullable();
            $table->string('booked_by_name', 150)->nullable();
            $table->timestampTz('booked_at');

            $table->timestampsTz();

            $table->index(['scheduled_date', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".corporate_mcu_bookings ADD CONSTRAINT corporate_mcu_bookings_status_check
            CHECK (status IN ('dijadwalkan','selesai','dibatalkan'))");
        DB::statement("ALTER TABLE " . self::S . ".corporate_mcu_bookings ADD CONSTRAINT corporate_mcu_bookings_count_check
            CHECK (employee_count > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.corporate_mcu_bookings');
    }
};
