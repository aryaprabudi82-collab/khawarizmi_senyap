<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambah 'operasi' ke source_type — tindakan operasi (clinical.operations)
 * sekarang ikut tersinkron jadi baris tagihan, lihat
 * InvoiceService::syncOperationCharges(). Pola sama dengan migrasi
 * 2026_09_11_000001_widen_charge_lines_source_check yang menambah
 * 'tindakan_ralan'.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.charge_lines DROP CONSTRAINT charge_lines_source_check');
        DB::statement("ALTER TABLE " . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat','order_penunjang','tindakan_ralan','operasi'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.charge_lines DROP CONSTRAINT charge_lines_source_check');
        DB::statement("ALTER TABLE " . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat','order_penunjang','tindakan_ralan'))");
    }
};
