<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain F Khanza item B dari 4 sub-order yang disepakati — rantai
 * pengadaan ke suplier dapur. Paralel persis dengan
 * 2026_10_01_000001_create_inventory_procurement_tables.php (domain E
 * item B) — cuma beda nama kode Khanza:
 *
 *  - dapur_pembelian (menu "Pengadaan Barang Dapur") = pembuatan PO,
 *    padanan ipsrs_pengadaan_barang.
 *  - surat_pemesanan_dapur = cetak PO, dokumen dari data yang sama,
 *    gerbang terpisah — padanan surat_pemesanan_non_medis.
 *  - dapur_pemesanan (menu "Penerimaan Barang Dapur" — nama kode
 *    menyesatkan, isinya penerimaan bukan pemesanan, quirk penamaan
 *    Khanza yang sama seperti bayar_pemesanan_obat farmasi) = terima
 *    barang dari suplier, padanan penerimaan_non_medis.
 *  - verifikasi_penerimaan_dapur = QC pasca-terima, padanan
 *    verifikasi_penerimaan_logistik.
 *  - dapur_returbeli = retur ke suplier, padanan ipsrs_returbeli,
 *    pakai source='retur-suplier' yang sudah terdaftar sejak migrasi
 *    awal kitchen.StockLedger (item A) tapi belum pernah dipanggil.
 *
 * dapur_stok_keluar TIDAK dapat kode baru di sini — sudah terpenuhi
 * RequisitionService::fulfill() sejak item A, sama seperti
 * ipsrs_stok_keluar di domain E.
 */
return new class extends Migration
{
    private const S = 'kitchen';

    public function up(): void
    {
        $this->createPurchaseOrders();
        $this->createGoodsReceipts();
        $this->createSupplierReturns();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.supplier_return_items');
        Schema::dropIfExists(self::S . '.supplier_returns');
        Schema::dropIfExists(self::S . '.goods_receipt_items');
        Schema::dropIfExists(self::S . '.goods_receipts');
        Schema::dropIfExists(self::S . '.purchase_order_items');
        Schema::dropIfExists(self::S . '.purchase_orders');
    }

    private function createPurchaseOrders(): void
    {
        Schema::create(self::S . '.purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('po_number', 24)->unique();
            $table->foreignId('supplier_id')->constrained(self::S . '.suppliers');
            $table->foreignId('requisition_id')->nullable()->constrained(self::S . '.requisitions')->nullOnDelete();
            $table->string('status', 24)->default('draf')
                ->comment('draf, dipesan, diterima-sebagian, diterima, dibatalkan');
            $table->timestampTz('ordered_at')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement("ALTER TABLE " . self::S . ".purchase_orders ADD CONSTRAINT purchase_orders_status_check
            CHECK (status IN ('draf','dipesan','diterima-sebagian','diterima','dibatalkan'))");

        Schema::create(self::S . '.purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->string('item_name', 200);
            $table->string('unit_of_measure', 20);
            $table->decimal('quantity_ordered', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->index('purchase_order_id');
        });
    }

    private function createGoodsReceipts(): void
    {
        Schema::create(self::S . '.goods_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('receipt_number', 24)->unique();
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('status', 20)->default('diterima')->comment('diterima, terverifikasi');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_outcome', 20)->nullable();
            $table->text('verification_note')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement("ALTER TABLE " . self::S . ".goods_receipts ADD CONSTRAINT goods_receipts_status_check
            CHECK (status IN ('diterima','terverifikasi'))");
        DB::statement("ALTER TABLE " . self::S . ".goods_receipts ADD CONSTRAINT goods_receipts_verification_outcome_check
            CHECK (verification_outcome IS NULL OR verification_outcome IN ('sesuai','tidak-sesuai'))");

        Schema::create(self::S . '.goods_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('goods_receipt_id')->constrained(self::S . '.goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained(self::S . '.purchase_order_items')->nullOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->decimal('quantity_received', 12, 2);
            $table->index('goods_receipt_id');
        });
    }

    private function createSupplierReturns(): void
    {
        Schema::create(self::S . '.supplier_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('return_number', 24)->unique();
            $table->foreignId('supplier_id')->constrained(self::S . '.suppliers');
            $table->foreignId('goods_receipt_id')->nullable()->constrained(self::S . '.goods_receipts')->nullOnDelete();
            $table->text('reason');
            $table->timestampTz('returned_at');
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->string('status', 20)->default('diajukan')->comment('diajukan, selesai');
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement("ALTER TABLE " . self::S . ".supplier_returns ADD CONSTRAINT supplier_returns_status_check
            CHECK (status IN ('diajukan','selesai'))");

        Schema::create(self::S . '.supplier_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('supplier_return_id')->constrained(self::S . '.supplier_returns')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->decimal('quantity', 12, 2);
            $table->string('note', 255)->nullable();
            $table->index('supplier_return_id');
        });
    }
};
