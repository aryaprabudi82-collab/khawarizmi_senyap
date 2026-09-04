<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain F Khanza item C dari 4 sub-order yang disepakati — stok
 * opname & riwayat/sirkulasi barang dapur. Paralel persis dengan
 * 2026_10_02_000001_create_inventory_stock_opnames_table.php (domain
 * E item C).
 *
 * dapur_opname — sebelumnya cuma dilayani MasterDataController::
 * opname() ad-hoc (koreksi 1 barang sekaligus, tanpa sesi/lembar
 * hitung, digerbangi dapur_barang). Menerapkan keputusan yang SAMA
 * seperti domain E item C (dikonfirmasi user via AskUserQuestion di
 * sana untuk fork desain yang identik) — dibangun sebagai sesi opname
 * sungguhan, bukan sekadar regerbangi. opname() ad-hoc lama TETAP ada
 * di bawah dapur_barang untuk koreksi cepat di luar sesi resmi.
 *
 * dapur_riwayat_barang, sirkulasi_dapur, sirkulasi_dapur2 — 3 kode
 * laporan riwayat/sirkulasi, tidak butuh tabel baru — dibaca dari
 * kitchen.stock_movements yang sudah ada, digabung satu layar
 * (StockReportController) digerbangi dapur_riwayat_barang sebagai
 * wakil, mengikuti pola ipsrs_riwayat_barang domain E.
 */
return new class extends Migration
{
    private const S = 'kitchen';

    public function up(): void
    {
        Schema::create(self::S . '.stock_opnames', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('opname_number', 24)->unique();
            $table->string('status', 20)->default('draf')->comment('draf, selesai');
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".stock_opnames ADD CONSTRAINT stock_opnames_status_check
            CHECK (status IN ('draf','selesai'))");

        Schema::create(self::S . '.stock_opname_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('opname_id')->constrained(self::S . '.stock_opnames')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');

            $table->decimal('system_quantity', 12, 2)->comment('Saldo sistem saat baris ditambahkan ke sesi, snapshot — bukan dibaca ulang saat selesai');
            $table->decimal('counted_quantity', 12, 2)->nullable();
            $table->string('note', 255)->nullable();

            $table->index('opname_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.stock_opname_items');
        Schema::dropIfExists(self::S . '.stock_opnames');
    }
};
