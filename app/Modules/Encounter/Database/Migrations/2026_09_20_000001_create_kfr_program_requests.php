<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * layanan_program_kfr (Khanza domain A, "Permintaan Layanan Program KFR",
 * kelas DlgCariPermintaanLayananProgramKFR, paket Java "permintaan") —
 * genuinely encounter. Kode ini juga muncul di baris terpisah domain M
 * ("Layanan Program KFR", kelas RMLayananProgramKFR, paket "rekammedis")
 * untuk dokumentasi klinis layanan KFR yang sudah diberikan — itu bagian
 * domain M, belum digarap di sini, cuma sisi PERMINTAAN/rujukannya.
 *
 * Diperlakukan sama seperti rujukan_keluar: permintaan program KFR (mis.
 * fisioterapi pasca-stroke, terapi wicara) adalah keputusan klinis dokter
 * merujuk pasien ke layanan rehabilitasi, bukan tindakan loket — gerbang
 * sendiri, dikecualikan dari petugas-daftar/perawat, sama seperti
 * rujukan_keluar (lihat migrasi encounter.outgoing_referrals).
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        Schema::create(self::S . '.kfr_program_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_number', 24)->unique();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->string('program_name', 200)->comment('mis. Fisioterapi Pasca-Stroke, Terapi Wicara');
            $table->text('reason');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_name', 150)->nullable();
            $table->timestampTz('requested_at');

            $table->string('status', 20)->default('diminta');
            $table->timestampsTz();

            $table->index('registration_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".kfr_program_requests ADD CONSTRAINT kfr_program_requests_status_check
            CHECK (status IN ('diminta','dibatalkan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.kfr_program_requests');
    }
};
