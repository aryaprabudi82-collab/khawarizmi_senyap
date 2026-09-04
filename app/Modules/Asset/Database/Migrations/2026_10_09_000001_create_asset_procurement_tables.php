<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain G Khanza item B dari 4 sub-order yang disepakati — rantai
 * pengadaan aset/inventaris. Beda MENDASAR dari rantai pengadaan
 * inventory/kitchen/pharmacy: aset dilacak PER-UNIT lewat asset_number
 * masing-masing (bukan kuantitas fungibel dalam satu baris quantity_
 * on_hand) — jadi "menerima barang" di sini berarti membuat baris
 * asset.assets BARU sebanyak kuantitas diterima, bukan menambah
 * kuantitas ke baris yang sudah ada. Konsekuensinya:
 *  - purchase_order_items TIDAK merujuk ke item master yang sudah ada
 *    (tidak ada Item/barang catalog untuk aset) — barisnya sendiri
 *    yang mendeskripsikan aset yang mau dibeli (nama, kategori, jenis,
 *    produsen, merk), disalin ke tiap baris asset.assets yang dibuat
 *    saat diterima.
 *  - Tidak ada StockLedger/stock_movements di sini sama sekali —
 *    penerimaan dan hibah SAMA-SAMA langsung memanggil
 *    AssetService::createAsset() N kali (N = kuantitas), bukan lewat
 *    ledger.
 *  - Tidak ada retur_ke_suplier untuk aset — domain G Khanza sendiri
 *    tidak punya kode itu (beda dari domain E/F yang punya
 *    ipsrs_returbeli/dapur_returbeli) — barang rusak diselesaikan
 *    lewat status Asset (dihapuskan), bukan mekanisme retur terpisah.
 *
 * rekap_pengajuan_aset_departemen TIDAK dapat tabel/layar sendiri —
 * dilebur jadi tab rekap di layar pengajuan yang sama (cuma 1 kode,
 * beda dari domain D/E/F item D yang punya 9-14 kode rekap
 * yang menjustifikasi item terpisah).
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        $this->createSuppliers();
        $this->createRequisitions();
        $this->createPurchaseOrders();
        $this->createGoodsReceipts();
        $this->createDonations();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.donation_receipt_items');
        Schema::dropIfExists(self::S . '.donation_receipts');
        Schema::dropIfExists(self::S . '.donors');
        Schema::dropIfExists(self::S . '.goods_receipt_items');
        Schema::dropIfExists(self::S . '.goods_receipts');
        Schema::dropIfExists(self::S . '.purchase_order_items');
        Schema::dropIfExists(self::S . '.purchase_orders');
        Schema::dropIfExists(self::S . '.requisition_items');
        Schema::dropIfExists(self::S . '.requisitions');
        Schema::dropIfExists(self::S . '.suppliers');
    }

    /** suplier_inventaris. */
    private function createSuppliers(): void
    {
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
    }

    /** pengajuan_asetinventaris (+ rekap_pengajuan_aset_departemen sebagai tab, bukan tabel terpisah). */
    private function createRequisitions(): void
    {
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
            $table->string('item_name', 200);
            $table->foreignId('category_id')->nullable()->constrained(self::S . '.categories');

            $table->decimal('quantity_requested', 10, 0);

            $table->index('requisition_id');
        });
    }

    /** pengadaan_aset_inventaris. */
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

            // Tidak ada Item/barang catalog untuk aset — baris ini sendiri
            // yang mendeskripsikan aset yang mau dibeli, disalin ke tiap
            // baris asset.assets yang dibuat saat diterima.
            $table->string('item_name', 200);
            $table->foreignId('category_id')->constrained(self::S . '.categories');
            $table->foreignId('type_id')->nullable()->constrained(self::S . '.types');
            $table->foreignId('manufacturer_id')->nullable()->constrained(self::S . '.manufacturers');
            $table->string('brand', 100)->nullable();

            $table->decimal('quantity_ordered', 10, 0);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('quantity_received', 10, 0)->default(0);
            $table->index('purchase_order_id');
        });
    }

    /** penerimaan_aset_inventaris — tiap baris diterima membuat N baris asset.assets baru, bukan menambah kuantitas. */
    private function createGoodsReceipts(): void
    {
        Schema::create(self::S . '.goods_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('receipt_number', 24)->unique();
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestampsTz();
        });

        Schema::create(self::S . '.goods_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('goods_receipt_id')->constrained(self::S . '.goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained(self::S . '.purchase_order_items');
            $table->decimal('quantity_received', 10, 0);
            $table->index('goods_receipt_id');
        });
    }

    /** hibah_aset_inventaris (+ asal_hibah, kode reused lintas-domain — lihat catatan modul, sudah context=pharmacy). */
    private function createDonations(): void
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
            $table->unsignedBigInteger('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create(self::S . '.donation_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('donation_receipt_id')->constrained(self::S . '.donation_receipts')->cascadeOnDelete();
            $table->string('item_name', 200);
            $table->foreignId('category_id')->constrained(self::S . '.categories');
            $table->decimal('quantity', 10, 0);
            $table->index('donation_receipt_id');
        });
    }
};
