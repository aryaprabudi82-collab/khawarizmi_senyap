<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks kitchen: bahan pangan & penunjang dapur/gizi (Khanza domain F
 * "Dapur & Gizi", paket Java "dapur", 25 kode genuine di context=kitchen
 * — dikonfirmasi bersih tanpa mis-tagging lewat
 * Khanza_Functional_Dependency_Map.xlsx & permissions.json; asal_hibah
 * dan satuan_barang yang ikut nongol di menu domain F adalah kode
 * reused lintas-domain yang SUDAH diselesaikan ke context=pharmacy
 * saat domain D dibangun, bukan milik domain F).
 *
 * Context baru sepenuhnya (belum ada modul apa pun sebelum sesi ini) —
 * beda dari domain E yang tinggal memperluas modul inventory yang
 * sudah berdiri sejak Wave 1. Arsitekturnya SENGAJA meniru
 * inventory (item non-batch, StockLedger UPDATE bersyarat) persis,
 * karena Khanza sendiri memberi domain F struktur menu yang nyaris
 * identik dengan domain E (master->pengajuan->PO->penerimaan->
 * verifikasi->retur->opname->riwayat->hibah->rekap) — cuma beda
 * paket Java (dapur vs ipsrs) dan istilah (dapur_pembelian dst.).
 * Sub-order 4 item (A/B/C/D) dikonfirmasi user via AskUserQuestion,
 * paralel dengan domain E.
 *
 * Item A (migrasi ini): dapur_barang (master barang dapur) dan
 * dapur_suplier (master suplier dapur) menggerbangi layar master;
 * pengajuan_barang_dapur menggerbangi alur permintaan unit
 * (diajukan->disetujui->dikeluarkan) — permintaan_dapur (menu
 * terpisah di Khanza) TIDAK jadi layar sendiri, sudah terpenuhi
 * listing yang sama, pola identik dengan permintaan_non_medis di
 * domain E item A.
 */
return new class extends Migration
{
    private const S = 'kitchen';

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
            $table->string('unit_of_measure', 20)->comment('Satuan: kg, liter, pcs, dus, dst.');
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
