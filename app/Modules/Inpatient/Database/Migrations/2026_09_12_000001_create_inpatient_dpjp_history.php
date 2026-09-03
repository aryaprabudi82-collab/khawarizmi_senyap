<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dpjp_ranap (Khanza domain A, kelas DlgDpjp) — pelengkap admisi yang sudah
 * ada. DPJP awal sudah tersnapshot dari registrasi saat admisi dibuat
 * (lihat catatan migrasi inpatient pertama), tapi belum ada aksi
 * MENGGANTI DPJP di tengah rawatan — padahal alih rawat/konsul lintas
 * spesialisasi adalah kejadian normal selama rawat inap.
 *
 * Satu baris per periode DPJP, pola sama persis dengan
 * hr.employee_position_history — baris baru otomatis menutup yang masih
 * terbuka. Perlu jejaknya (bukan sekadar menimpa admissions.dpjp_name)
 * karena siapa bertanggung jawab atas pasien pada rentang waktu tertentu
 * adalah bagian dari akuntabilitas klinis, bukan detail administratif.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        Schema::create(self::S . '.dpjp_history', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('admission_id')->constrained(self::S . '.admissions')->cascadeOnDelete();
            $table->unsignedBigInteger('practitioner_id')->comment('ID praktisi organization, referensi longgar');
            $table->string('practitioner_name', 150);

            $table->timestampTz('start_at');
            $table->timestampTz('end_at')->nullable();
            $table->text('reason')->nullable()->comment('Alasan alih rawat/konsul, kosong untuk DPJP awal');

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestampsTz();

            $table->index(['admission_id', 'start_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".dpjp_history ADD CONSTRAINT dpjp_history_order_check
            CHECK (end_at IS NULL OR end_at >= start_at)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.dpjp_history');
    }
};
