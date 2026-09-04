<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain E Khanza item D (terakhir) dari 4 sub-order yang disepakati —
 * hibah barang non-medis & rekap gabungan.
 *
 * hibah_non_medis: mengikuti pola donors+donation_receipts farmasi
 * persis (asal_hibah/hibah_obat_bhp), disederhanakan ke model
 * non-batch inventory (tanpa batch_number/expiry_date — barang
 * non-medis tidak kedaluwarsa per-batch seperti obat). Stok masuk
 * lewat StockLedger::receive(source: 'hibah') yang SUDAH terdaftar di
 * migrasi paling awal inventory (lihat komentar kolom source di
 * 2026_06_01_000001) tapi belum pernah dipanggil kode manapun — beda
 * dari farmasi yang perlu widen kolom kind (inventory.stock_movements.
 * kind cuma masuk/keluar/opname, sudah cukup — 'hibah' cukup lewat
 * source, sebab tidak ada laporan nilai pengadaan yang dihitung dari
 * stock_movements di sini: nilai_penerimaan_vendor_nonmedis_perbulan
 * dihitung dari goods_receipt_items/purchase_order_items yang ada
 * unit_price-nya, donasi tidak pernah lewat goods_receipts sama
 * sekali jadi otomatis tidak ikut terhitung).
 *
 * 14 kode rekap/ringkasan sisanya (rekap_permintaan_non_medis,
 * ringkasan_pengajuan/pemesanan/pengadaan/penerimaan/stokkeluar/
 * returbeli_nonmedis, ipsrs_pengeluaran_harian, ipsrs_rekap_pengadaan,
 * ipsrs_rekap_stok_keluar, ipsrs_pengadaan/stokkeluar_pertanggal,
 * rekap_pemesanan_non_medis, nilai_penerimaan_vendor_nonmedis_perbulan)
 * semuanya laporan/browsing atas requisitions/purchase_orders/
 * goods_receipts/supplier_returns/stock_movements yang SUDAH ADA —
 * digabung SATU layar (InventoryRecapController), digerbangi
 * ipsrs_rekap_pengadaan sebagai wakil (dikonfirmasi user lewat
 * AskUserQuestion, dipilih karena namanya paling generik mewakili
 * "rekap IPSRS" secara keseluruhan). Tidak ada tabel baru untuknya
 * di migrasi ini.
 */
return new class extends Migration
{
    private const S = 'inventory';

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
