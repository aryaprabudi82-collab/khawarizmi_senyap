<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambah 'tindakan_ralan' ke source_type — tindakan rawat jalan
 * (clinical.procedures) sekarang ikut tersinkron jadi baris tagihan, lihat
 * InvoiceService::syncProcedureCharges(). charge_lines dipartisi bulanan,
 * tapi CHECK constraint di tabel induk otomatis berlaku ke seluruh partisi
 * (Postgres 11+), jadi cukup diubah sekali di sini.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.charge_lines DROP CONSTRAINT charge_lines_source_check');
        DB::statement("ALTER TABLE " . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat','order_penunjang','tindakan_ralan'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.charge_lines DROP CONSTRAINT charge_lines_source_check');
        DB::statement("ALTER TABLE " . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat','order_penunjang'))");
    }
};
