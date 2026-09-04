<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain D Khanza item 2 dari 6 sub-order yang disepakati — rantai
 * pengadaan. Desain dikonfirmasi user (AskUserQuestion) sebelum dibangun:
 *
 *  pengajuan_barang_medis (unit minta) -> pengadaan_obat/DlgPembelian
 *  (catat PO ke suplier) -> bayar_pemesanan_obat (terima barang SEKALIGUS
 *  catat pembayaran — satu access flag Khanza menggerbangi DUA dialog:
 *  DlgPemesanan di inventory dan KeuanganBayarPemesananFarmasi di
 *  keuangan, dikonfirmasi lewat Khanza_Functional_Dependency_Map.xlsx)
 *  -> verifikasi_penerimaan_farmasi (QC pasca-terima, bukan gerbang
 *  ketersediaan stok — beda dari pola envlab yang verifikasinya baru
 *  membuka tahap berikut) -> retur_ke_suplier (kalau ada retur).
 *
 *  pemesanan_obat (Surat Pemesanan) BUKAN entitas terpisah — cetak
 *  dokumen PO dari purchase_orders yang sama (pola sama dengan
 *  barcoderalan/barcoderanap: data sama, tampilan cetak beda gerbang).
 *
 *  5 kode ringkasan_* (ringkasan_pengajuan_obat, ringkasan_pemesanan_obat,
 *  ringkasan_pengadaan_obat, ringkasan_penerimaan_obat,
 *  ringkasan_retur_suplier_obat) tidak jadi layar terpisah — listing di
 *  layar induknya masing-masing sudah menampilkan ringkasan itu.
 *  nilai_penerimaan_vendor_farmasi_perbulan jadi rincian per-suplier-
 *  per-bulan pada layar rekap_pemesanan (item 6 sub-order, belum
 *  dibangun di migrasi ini).
 *
 * Barang diterima ke lokasi GUDANG (gudang pusat, sudah ada dari
 * PharmacySeeder) lewat StockLedger::receive() yang sudah ada dan teruji
 * — bukan menulis langsung ke stock_batches. Mutasi dari gudang ke depo
 * ruangan menyusul di item 3 sub-order (stok & batch ops, kode
 * mutasi_barang).
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        $this->createDrugRequisitions();
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
        Schema::dropIfExists(self::S . '.drug_requisition_items');
        Schema::dropIfExists(self::S . '.drug_requisitions');
    }

    /** pengajuan_barang_medis — pola sama persis dengan inventory.requisitions (non-medis), disalin untuk konsistensi lintas-context. */
    private function createDrugRequisitions(): void
    {
        Schema::create(self::S . '.drug_requisitions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('requisition_number', 24)->unique();
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');
            $table->string('unit_name', 150)->comment('Disalin saat pengajuan');

            $table->string('status', 20)->default('diajukan');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".drug_requisitions ADD CONSTRAINT drug_requisitions_status_check
            CHECK (status IN ('diajukan','disetujui','ditolak','selesai'))");

        Schema::create(self::S . '.drug_requisition_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('requisition_id')->constrained(self::S . '.drug_requisitions')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200)->comment('Disalin saat pengajuan');
            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('quantity_fulfilled', 12, 2)->default(0);
            $table->index('requisition_id');
        });
    }

    /** pengadaan_obat (DlgPembelian) — PO ke suplier. Boleh berasal dari pengajuan atau berdiri sendiri. pemesanan_obat = cetak dokumen dari sini, bukan tabel sendiri. */
    private function createPurchaseOrders(): void
    {
        Schema::create(self::S . '.purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('po_number', 24)->unique();
            $table->foreignId('supplier_id')->constrained(self::S . '.suppliers');
            $table->foreignId('requisition_id')->nullable()->constrained(self::S . '.drug_requisitions')->nullOnDelete();

            $table->string('status', 24)->default('draf')
                ->comment('draf, dipesan, diterima-sebagian, diterima, dibatalkan');
            $table->timestampTz('ordered_at')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".purchase_orders ADD CONSTRAINT purchase_orders_status_check
            CHECK (status IN ('draf','dipesan','diterima-sebagian','diterima','dibatalkan'))");

        Schema::create(self::S . '.purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200)->comment('Disalin saat PO dibuat');
            $table->string('unit', 20)->comment('Satuan pemesanan, mis. Boks — lihat pharmacy.drug_units untuk konversinya');
            $table->decimal('quantity_ordered', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->index('purchase_order_id');
        });
    }

    /**
     * bayar_pemesanan_obat — terima barang sekaligus catat pembayaran
     * (lihat catatan kelas migrasi). Penerimaan memicu StockLedger::receive()
     * ke lokasi GUDANG lewat GoodsReceiptService, bukan ditulis di sini.
     */
    private function createGoodsReceipts(): void
    {
        Schema::create(self::S . '.goods_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('receipt_number', 24)->unique();
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('received_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->string('invoice_number', 60)->nullable();
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('payment_status', 20)->default('belum-bayar');

            $table->string('status', 20)->default('diterima')->comment('diterima, terverifikasi');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_outcome', 20)->nullable()->comment('sesuai, tidak-sesuai — diisi verifikasi_penerimaan_farmasi');
            $table->text('verification_note')->nullable();

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".goods_receipts ADD CONSTRAINT goods_receipts_payment_status_check
            CHECK (payment_status IN ('belum-bayar','lunas'))");
        DB::statement("ALTER TABLE " . self::S . ".goods_receipts ADD CONSTRAINT goods_receipts_status_check
            CHECK (status IN ('diterima','terverifikasi'))");
        DB::statement("ALTER TABLE " . self::S . ".goods_receipts ADD CONSTRAINT goods_receipts_verification_outcome_check
            CHECK (verification_outcome IS NULL OR verification_outcome IN ('sesuai','tidak-sesuai'))");

        Schema::create(self::S . '.goods_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('goods_receipt_id')->constrained(self::S . '.goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained(self::S . '.purchase_order_items')->nullOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->decimal('quantity_received', 12, 2)->comment('Dalam satuan dasar obat (drugs.unit), sudah dikonversi dari satuan PO bila beda');
            $table->string('batch_number', 40);
            $table->date('expiry_date')->nullable();
            $table->decimal('cost_price', 14, 2)->default(0);
            $table->index('goods_receipt_id');
        });
    }

    /** retur_ke_suplier (DlgReturBeli). */
    private function createSupplierReturns(): void
    {
        Schema::create(self::S . '.supplier_returns', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('return_number', 24)->unique();
            $table->foreignId('supplier_id')->constrained(self::S . '.suppliers');
            $table->foreignId('goods_receipt_id')->nullable()->constrained(self::S . '.goods_receipts')->nullOnDelete();

            $table->text('reason');
            $table->timestampTz('returned_at');
            $table->unsignedBigInteger('returned_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->string('status', 20)->default('diajukan')->comment('diajukan, selesai');

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".supplier_returns ADD CONSTRAINT supplier_returns_status_check
            CHECK (status IN ('diajukan','selesai'))");

        Schema::create(self::S . '.supplier_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('supplier_return_id')->constrained(self::S . '.supplier_returns')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('batch_number', 40)->nullable();
            $table->decimal('quantity', 12, 2);
            $table->string('note', 255)->nullable();
            $table->index('supplier_return_id');
        });
    }
};
