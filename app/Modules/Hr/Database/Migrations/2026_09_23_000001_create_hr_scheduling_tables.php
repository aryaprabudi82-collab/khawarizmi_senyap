<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * jam_masuk (Jam Presensi) dan jadwal_pegawai (Jadwal Pegawai) — domain C,
 * paket Java "kepegawaian", genuinely hr. Bukan organization.
 * practice_schedules (itu jadwal PRAKTIK KLINIS dokter untuk booking
 * pasien) — ini jadwal SHIFT KERJA seluruh pegawai (termasuk non-klinis),
 * dua kebutuhan berbeda meski sama-sama "jadwal".
 *
 * work_shifts (jam_masuk): master jam kerja standar (Pagi/Siang/Malam,
 * dst.) berikut toleransi keterlambatan.
 *
 * duty_schedules (jadwal_pegawai): penugasan pegawai ke satu shift pada
 * satu tanggal. attendance_records.is_late/scheduled_shift_id ditambah di
 * sini juga — presensi_harian yang sudah ada sekarang bisa menghitung
 * keterlambatan sungguhan terhadap jadwal, bukan cuma mencatat jam masuk
 * mentah tanpa pembanding.
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        $this->createWorkShifts();
        $this->createDutySchedules();
        $this->widenAttendanceRecords();
    }

    public function down(): void
    {
        Schema::table(self::S . '.attendance_records', function (Blueprint $table) {
            $table->dropColumn(['duty_schedule_id', 'is_late']);
        });
        Schema::dropIfExists(self::S . '.duty_schedules');
        Schema::dropIfExists(self::S . '.work_shifts');
    }

    private function createWorkShifts(): void
    {
        Schema::create(self::S . '.work_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('tolerance_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    private function createDutySchedules(): void
    {
        Schema::create(self::S . '.duty_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('employee_id')->constrained(self::S . '.employees');
            $table->foreignId('work_shift_id')->constrained(self::S . '.work_shifts');
            $table->date('schedule_date');

            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID organization.units, referensi longgar');
            $table->string('unit_name', 150)->nullable();

            $table->string('status', 20)->default('terjadwal');
            $table->string('note', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['employee_id', 'schedule_date']);
        });

        DB::statement("ALTER TABLE " . self::S . ".duty_schedules ADD CONSTRAINT duty_schedules_status_check
            CHECK (status IN ('terjadwal','dibatalkan'))");
    }

    private function widenAttendanceRecords(): void
    {
        Schema::table(self::S . '.attendance_records', function (Blueprint $table) {
            $table->foreignId('duty_schedule_id')->nullable()->after('employee_id')
                ->constrained(self::S . '.duty_schedules')->nullOnDelete();
            $table->boolean('is_late')->default(false)->after('status');
        });
    }
};
