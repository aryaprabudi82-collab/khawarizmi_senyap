<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * booking_operasi (Khanza domain A, "Jadwal Operasi", kelas DlgBookingOperasi,
 * paket Java "permintaan") — genuinely encounter, sama seperti booking_
 * registrasi/booking_periksa yang sudah dibangun lebih dulu.
 *
 * Beda dari booking_registrasi/booking_periksa: keduanya membuat baris
 * encounter.registrations langsung bertanggal masa depan (pasien SUDAH
 * pasti akan datang di tanggal itu). Jadwal operasi baru dijadwalkan
 * setelah pasien sudah terdaftar (biasanya dari kunjungan ralan/ranap yang
 * sedang berjalan, dokter memutuskan perlu operasi) — makanya tabel ini
 * MEWAJIBKAN registration_id yang sudah ada, bukan membuat registrasi baru.
 *
 * operasi (clinical.operations, kode Khanza terpisah — lihat migrasinya)
 * TIDAK mewajibkan booking_id di sini: operasi cito/darurat sah dicatat
 * tanpa jadwal sebelumnya.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        Schema::create(self::S . '.operation_bookings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('booking_number', 24)->unique();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->string('procedure_name', 200);
            $table->unsignedBigInteger('surgeon_id')->nullable();
            $table->string('surgeon_name', 150)->nullable();
            $table->string('operating_room', 50)->nullable();
            $table->timestampTz('scheduled_at');

            $table->string('status', 20)->default('dijadwalkan');
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('booked_by')->nullable();
            $table->string('booked_by_name', 150)->nullable();
            $table->timestampTz('booked_at');

            $table->timestampsTz();

            $table->index('registration_id');
            $table->index(['scheduled_at', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".operation_bookings ADD CONSTRAINT operation_bookings_status_check
            CHECK (status IN ('dijadwalkan','selesai','dibatalkan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.operation_bookings');
    }
};
