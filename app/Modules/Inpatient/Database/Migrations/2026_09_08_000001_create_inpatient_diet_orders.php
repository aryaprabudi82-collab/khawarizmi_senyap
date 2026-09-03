<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * diet_pasien (Khanza domain A) — order diet untuk pasien rawat inap.
 *
 * Bukan domain F ("Dapur & Gizi") seperti dugaan awal — 25 kode domain F
 * ternyata murni rantai pasok bahan dapur (suplier, pembelian, stok
 * opname, retur), bukan order diet pasien sama sekali. diet_pasien
 * sesungguhnya ada di domain A, dan asuhan gizi klinis penuh (skrining,
 * ADIME) ada di domain M/clinical — keduanya beda dari sini. Wave 1 ini
 * cuma order diet dasar (jenis + catatan tekstur/pantangan), bukan
 * skrining gizi berjenjang atau rantai pasok dapur.
 *
 * Satu baris per periode order — order baru otomatis menutup yang masih
 * aktif, pola sama dengan hr.employee_position_history.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        Schema::create(self::S . '.diet_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('admission_id')->constrained(self::S . '.admissions')->cascadeOnDelete();
            $table->string('diet_type', 30)->comment('biasa, lunak, cair, bubur, diabetes, rendah-garam, rendah-lemak, tinggi-protein, bebas-gluten, lainnya');
            $table->text('note')->nullable()->comment('Tekstur, pantangan, alergi, dsb.');

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('aktif');

            $table->unsignedBigInteger('ordered_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->string('ordered_by_name', 150)->nullable();

            $table->timestampsTz();

            $table->index(['admission_id', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".diet_orders ADD CONSTRAINT diet_orders_type_check
            CHECK (diet_type IN ('biasa','lunak','cair','bubur','diabetes','rendah-garam','rendah-lemak','tinggi-protein','bebas-gluten','lainnya'))");
        DB::statement("ALTER TABLE " . self::S . ".diet_orders ADD CONSTRAINT diet_orders_status_check
            CHECK (status IN ('aktif','dihentikan'))");
        DB::statement("ALTER TABLE " . self::S . ".diet_orders ADD CONSTRAINT diet_orders_date_order_check
            CHECK (end_date IS NULL OR end_date >= start_date)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.diet_orders');
    }
};
