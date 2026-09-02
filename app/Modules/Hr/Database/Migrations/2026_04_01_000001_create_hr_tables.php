<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks hr: master pegawai, pengajuan cuti, dan presensi harian.
 *
 * Sengaja terpisah dari organization.practitioners meski sama-sama "orang
 * yang bekerja di RS": practitioners adalah catatan untuk penjadwalan
 * klinis (SIP, spesialisasi, unit tempat praktik), sedangkan employees
 * adalah catatan kepegawaian (status kerja, riwayat cuti, presensi) untuk
 * SELURUH pegawai, bukan cuma yang berpraktik klinis. Seorang dokter yang
 * juga pegawai tetap akan punya baris di kedua tabel, dihubungkan lewat
 * practitioner_id yang nullable — bukan foreign key lintas schema,
 * konsisten dengan pola referensi longgar di seluruh sistem ini.
 *
 * Presensi di sini dicatat manual (checkin/checkout atau entri petugas),
 * BUKAN integrasi ke perangkat barcode/sidik jari Khanza (barcode,
 * sidikjari, temporary_presensi) — itu integrasi perangkat keras lokal
 * dengan SDK vendor spesifik, beda kelas dari API BPJS/SATUSEHAT yang
 * punya kontrak jelas untuk diadaptasi. Belum digarap di wave ini.
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        Schema::create(self::S . '.employees', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('employee_number', 20)->unique()->comment('NIP/NIK pegawai');
            $table->string('name', 150);
            $table->string('position', 100)->comment('Jabatan');
            $table->string('employment_type', 20)->comment('tetap, kontrak, honorer, magang');

            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');
            $table->unsignedBigInteger('practitioner_id')->nullable()->comment('ID praktisi organization, referensi longgar — kalau pegawai ini juga praktisi klinis');

            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->boolean('is_active')->default(true);

            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();

            $table->timestampsTz();

            $table->index('unit_id');
            $table->index('is_active');
        });

        DB::statement("ALTER TABLE " . self::S . ".employees ADD CONSTRAINT employees_employment_type_check
            CHECK (employment_type IN ('tetap','kontrak','honorer','magang'))");

        Schema::create(self::S . '.leave_types', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('annual_quota')->nullable()->comment('Jatah hari per tahun, null = tidak dibatasi (mis. cuti sakit)');
            $table->boolean('is_active')->default(true);
        });

        Schema::create(self::S . '.leave_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees');
            $table->foreignId('leave_type_id')->constrained(self::S . '.leave_types');

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days_count');
            $table->text('reason')->nullable();

            $table->string('status', 20)->default('diajukan');
            $table->unsignedBigInteger('requested_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('approved_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".leave_requests ADD CONSTRAINT leave_requests_status_check
            CHECK (status IN ('diajukan','disetujui','ditolak','dibatalkan'))");
        DB::statement("ALTER TABLE " . self::S . ".leave_requests ADD CONSTRAINT leave_requests_date_order_check
            CHECK (end_date >= start_date)");

        Schema::create(self::S . '.attendance_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('employee_id')->constrained(self::S . '.employees');
            $table->date('attendance_date');

            $table->timestampTz('check_in_at')->nullable();
            $table->timestampTz('check_out_at')->nullable();
            $table->string('status', 20)->default('hadir');

            $table->unsignedBigInteger('recorded_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->string('note', 255)->nullable();

            $table->timestampsTz();

            $table->unique(['employee_id', 'attendance_date']);
        });

        DB::statement("ALTER TABLE " . self::S . ".attendance_records ADD CONSTRAINT attendance_records_status_check
            CHECK (status IN ('hadir','izin','sakit','alpha','cuti'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.attendance_records');
        Schema::dropIfExists(self::S . '.leave_requests');
        Schema::dropIfExists(self::S . '.leave_types');
        Schema::dropIfExists(self::S . '.employees');
    }
};
