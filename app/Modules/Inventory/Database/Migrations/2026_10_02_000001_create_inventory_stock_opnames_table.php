<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain E Khanza item C dari 4 sub-order yang disepakati — stok opname
 * & riwayat/sirkulasi barang.
 *
 * stok_opname_logistik (Stok Opname Non Medis, DlgInputStok versi ipsrs)
 * sebelumnya cuma dilayani MasterDataController::opname() — koreksi 1
 * barang sekaligus, tanpa sesi/lembar hitung, digerbangi ipsrs_barang
 * (bukan kodenya sendiri). Dikonfirmasi ke user (AskUserQuestion): itu
 * TIDAK dianggap setara dengan layar "Stok Opname Non Medis" Khanza yang
 * sesungguhnya sebuah lembar hitung fisik multi-barang per sesi —
 * dibangun ulang di sini sebagai sesi opname sungguhan, mengikuti pola
 * persis pharmacy.stock_opnames (domain D item 3). Beda dari farmasi:
 * inventory tidak punya konsep multi-lokasi (tidak ada stock_locations),
 * jadi sesi opname di sini tanpa location_id.
 *
 * opname() ad-hoc lama TETAP ada di bawah ipsrs_barang untuk koreksi
 * cepat 1 barang di luar sesi resmi — dua mekanisme berbeda tujuan,
 * sama-sama lewat StockLedger::opname() sebagai satu-satunya jalan
 * penyesuaian, bukan duplikasi logika.
 *
 * ipsrs_riwayat_barang, sirkulasi_non_medis, sirkulasi_non_medis2 —
 * 3 kode laporan riwayat/sirkulasi, tidak butuh tabel baru — dibaca
 * dari inventory.stock_movements yang sudah ada, digabung satu layar
 * (StockReportController) digerbangi ipsrs_riwayat_barang sebagai
 * wakil, mengikuti pola sisa_stok farmasi (12 kode -> 1 gerbang).
 *
 * ipsrs_stok_keluar dan pengambilan_penunjang_utd TIDAK dapat kode/layar
 * baru — RequisitionService::fulfill() sudah memanggil StockLedger::
 * issue() untuk semua unit termasuk UTD (UTD cuma unit pemohon biasa),
 * pola sama persis dengan resep_dokter/UTD di domain D item 4.
 */
return new class extends Migration
{
    private const S = 'inventory';

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
