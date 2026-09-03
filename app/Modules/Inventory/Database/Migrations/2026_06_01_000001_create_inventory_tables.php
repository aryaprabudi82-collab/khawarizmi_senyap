<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks inventory: barang non-medis dan penunjang.
 *
 * Arsitekturnya sengaja meniru pharmacy.StockLedger — UPDATE bersyarat
 * (WHERE quantity_on_hand >= ?) untuk pengurangan stok, dan setiap
 * perubahan saldo meninggalkan baris di stock_movements — tapi tanpa
 * kerumitan batch/FEFO/kedaluwarsa, karena barang non-medis (alat tulis,
 * consumable rumah tangga) tidak punya siklus hidup itu. quantity_on_hand
 * di items adalah cache; kebenarannya selalu bisa diuji ulang terhadap
 * stock_movements.
 */
return new class extends Migration
{
    private const S = 'inventory';

    public function up(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create(self::S . '.suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('contact_person', 100)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create(self::S . '.item_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        Schema::create(self::S . '.items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->foreignId('category_id')->constrained(self::S . '.item_categories');
            $table->string('unit_of_measure', 20)->comment('Satuan: pcs, box, rim, dus, dst.');
            $table->decimal('quantity_on_hand', 12, 2)->default(0)->comment('Cache saldo — kebenarannya diuji ulang terhadap stock_movements');
            $table->unsignedInteger('reorder_point')->nullable()->comment('Ambang stok minimum, null = tidak dipantau');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('category_id');
        });

        Schema::create(self::S . '.stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->string('kind', 10)->comment('masuk, keluar, opname');
            $table->string('source', 20)->comment('pembelian, hibah, opname, retur-suplier, permintaan-unit');
            $table->decimal('quantity', 12, 2)->comment('Positif untuk masuk, negatif untuk keluar');
            $table->decimal('balance_after', 12, 2);

            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 255)->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('moved_at');

            $table->index(['item_id', 'moved_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','opname'))");

        Schema::create(self::S . '.requisitions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('requisition_number', 24)->unique();
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');
            $table->string('unit_name', 150)->comment('Disalin saat pengajuan — nama unit tidak boleh ikut berubah kalau data organization berubah kemudian');

            $table->string('status', 20)->default('diajukan');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('decided_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".requisitions ADD CONSTRAINT requisitions_status_check
            CHECK (status IN ('diajukan','disetujui','ditolak','selesai'))");

        Schema::create(self::S . '.requisition_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('requisition_id')->constrained(self::S . '.requisitions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');

            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('quantity_issued', 12, 2)->nullable();

            $table->index('requisition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.requisition_items');
        Schema::dropIfExists(self::S . '.requisitions');
        Schema::dropIfExists(self::S . '.stock_movements');
        Schema::dropIfExists(self::S . '.items');
        Schema::dropIfExists(self::S . '.item_categories');
        Schema::dropIfExists(self::S . '.suppliers');
        Schema::dropIfExists(self::S . '.number_sequences');
    }
};
