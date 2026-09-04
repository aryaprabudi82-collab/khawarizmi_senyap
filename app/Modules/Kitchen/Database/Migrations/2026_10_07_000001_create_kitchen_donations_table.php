<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain F Khanza item D (terakhir) dari 4 sub-order yang disepakati —
 * hibah barang dapur & rekap gabungan. Paralel persis dengan
 * 2026_10_03_000001_create_inventory_donations_table.php (domain E
 * item D).
 *
 * hibah_dapur: mengikuti pola donors+donation_receipts persis
 * (hibah_non_medis/hibah_obat_bhp), disederhanakan ke model non-batch
 * kitchen. Stok masuk lewat StockLedger::receive(source: 'hibah')
 * yang sudah terdaftar sejak migrasi item A tapi belum pernah
 * dipanggil — zero perubahan skema stock_movements, sama seperti
 * domain E item D (tidak ada laporan nilai pengadaan yang dihitung
 * dari stock_movements di sini: nilai_penerimaan_vendor_dapur_perbulan
 * dihitung dari goods_receipt_items/purchase_order_items, donasi tidak
 * pernah lewat goods_receipts sama sekali).
 *
 * 10 kode rekap/ringkasan sisanya (ringkasan_pengajuan_dapur,
 * ringkasan_pemesanan_dapur, dapur_ringkasan_pembelian,
 * ringkasan_penerimaan_dapur, ringkasan_stokkeluar_dapur,
 * ringkasan_returbeli_dapur, biaya_pengadaan_dapur, rekap_pengadaan_dapur,
 * dapur_stokkeluar_pertanggal, nilai_penerimaan_vendor_dapur_perbulan)
 * semuanya laporan/browsing atas requisitions/purchase_orders/
 * goods_receipts/supplier_returns/stock_movements yang SUDAH ADA —
 * digabung SATU layar (KitchenRecapController), digerbangi
 * rekap_pengadaan_dapur sebagai wakil (dikonfirmasi user lewat
 * AskUserQuestion, dipilih karena namanya paling generik). Tidak ada
 * tabel baru untuknya di migrasi ini.
 */
return new class extends Migration
{
    private const S = 'kitchen';

    public function up(): void
    {
        Schema::create(self::S . '.donors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('contact', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create(self::S . '.donation_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('receipt_number', 24)->unique();
            $table->foreignId('donor_id')->constrained(self::S . '.donors');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('received_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();
        });

        Schema::create(self::S . '.donation_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('donation_receipt_id')->constrained(self::S . '.donation_receipts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->string('item_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->index('donation_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.donation_receipt_items');
        Schema::dropIfExists(self::S . '.donation_receipts');
        Schema::dropIfExists(self::S . '.donors');
    }
};
