<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks reporting: read model rekap kunjungan, diagnosis, dan pendapatan.
 *
 * Ini BUKAN view SQL yang men-JOIN langsung ke schema lain — migrasi tidak
 * boleh menyentuh schema lain sama sekali (lihat ContextBoundaryTest), dan
 * itu berlaku juga untuk definisi VIEW, bukan cuma tabel. Polanya sama
 * seperti billing -> finance: ReportingSyncService membaca view yang
 * diterbitkan konteks lain lewat kode aplikasi (yang memang boleh menyentuh
 * view lintas konteks), lalu menulis hasil rekapnya ke tabel milik
 * reporting sendiri di bawah ini. Layar/laporan HANYA membaca dari sini,
 * tidak pernah query langsung ke encounter/clinical/billing saat dibuka.
 *
 * Baris di sini bukan ledger append-only seperti charge_lines — angkanya
 * memang perlu dihitung ULANG tiap sinkronisasi berjalan (kunjungan baru
 * masuk sepanjang hari), jadi kuncinya unique per kelompok dan ditimpa
 * (upsert), bukan idempotent-insert-sekali seperti pola jurnal/tagihan.
 */
return new class extends Migration
{
    private const S = 'reporting';

    public function up(): void
    {
        Schema::create(self::S . '.daily_visit_summary', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->date('report_date');
            $table->unsignedBigInteger('unit_id');
            $table->string('unit_name', 150);
            $table->string('payer_kind', 20);

            $table->unsignedInteger('visit_count')->default(0);
            $table->timestampTz('synced_at');

            $table->unique(['report_date', 'unit_id', 'payer_kind'], 'daily_visit_summary_group_unique');
            $table->index('report_date');
        });

        Schema::create(self::S . '.diagnosis_frequency', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->date('report_date');
            $table->string('code', 12);
            $table->string('display', 255);

            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestampTz('synced_at');

            $table->unique(['report_date', 'code'], 'diagnosis_frequency_group_unique');
            $table->index('report_date');
        });

        Schema::create(self::S . '.daily_revenue_summary', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->date('report_date');
            $table->string('payer_kind', 20);

            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->timestampTz('synced_at');

            $table->unique(['report_date', 'payer_kind'], 'daily_revenue_summary_group_unique');
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.daily_revenue_summary');
        Schema::dropIfExists(self::S . '.diagnosis_frequency');
        Schema::dropIfExists(self::S . '.daily_visit_summary');
    }
};
