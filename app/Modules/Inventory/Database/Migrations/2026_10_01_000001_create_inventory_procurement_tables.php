<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain E Khanza ("Inventory Non-Medis & Penunjang") — item B dari 4
 * sub-order yang disepakati (A: master data, sudah terpenuhi tanpa kode
 * baru — lihat catatan di bawah; B: rantai pengadaan, migrasi ini;
 * C: stok opname & riwayat; D: hibah & rekap gabungan).
 *
 * Pemicu: domain E sebelumnya ditandai "Selesai" di tracker padahal
 * hanya py 2 gerbang literal (ipsrs_barang untuk master, dan
 * pengajuan_barang_nonmedis untuk alur permintaan unit sederhana) —
 * user secara eksplisit meminta domain E diperluas dengan rigor yang
 * sama dengan domain D (farmasi) yang baru selesai, bukan dibiarkan
 * cakupan Wave 1 yang lebih dangkal.
 *
 * Item A (ipsrs_jenis_barang, suplier_penunjang, stok_opname_logistik,
 * hibah_non_medis, ipsrs_stok_keluar) TIDAK butuh migrasi/kode baru —
 * semuanya SUDAH terpenuhi tabel & aksi yang ada sejak migrasi inventory
 * awal: inventory.item_categories + inventory.suppliers sudah ada
 * tabelnya DAN layar CRUD-nya (MasterDataController::storeCategory()/
 * storeSupplier(), digerbangi 'ipsrs_barang' yang sudah ada), begitu
 * juga StockLedger::opname() (stok_opname_logistik) dan
 * RequisitionService::fulfill() (ipsrs_stok_keluar, memanggil
 * StockLedger::issue()). MasterDataController::receive() sudah
 * menerima source='hibah' (hibah_non_medis) — tapi TANPA pencatatan
 * siapa pemberinya (donor), beda dari farmasi yang py tabel donors
 * sendiri. Untuk Wave 1 ini dianggap cukup (barang non-medis hibah
 * biasanya dari sumber internal RS/pemerintah, bukan yayasan luar
 * seperti obat) — kalau kebutuhan pelacakan donor non-medis jadi nyata,
 * bisa direvisit.
 *
 * Item B (migrasi ini) genuinely kode baru — receive() yang sudah ada
 * cuma "tambah jumlah + tag sumber", tanpa dokumen PO (suplier mana,
 * harga berapa) dan tanpa verifikasi terpisah, padahal Khanza py 5
 * dialog Delphi berbeda untuk pengadaan_barang/pemesanan(=penerimaan,
 * penamaan kelas Delphi tertukar sama seperti pharmacy)/verifikasi/
 * returbeli/suratpemesanan. Pola & penamaan tabel meniru persis
 * pharmacy.purchase_orders dkk. (migrasi 2026_09_27_000001), tanpa
 * konsep pembayaran (non-medis tidak py pola dual-dialog
 * inventory+keuangan seperti bayar_pemesanan_obat farmasi — dikonfirmasi
 * lewat xlsx, penerimaan_non_medis cuma satu baris paket "ipsrs").
 * surat_pemesanan_non_medis = cetak dokumen dari purchase_orders yang
 * sama, bukan entitas terpisah (pola sama dengan pemesanan_obat).
 * permintaan_non_medis (IPSRSPermintaan) TIDAK jadi tabel baru — sudah
 * terpenuhi listing inventory.requisitions yang ada (permintaan.index),
 * sama seperti resep_dokter di domain D.
 *
 * Retur ke suplier (ipsrs_returbeli) lewat StockLedger::issue() yang
 * sudah ada (source='retur-suplier' sudah terdaftar di komentar kolom
 * source sejak migrasi awal, tapi belum pernah benar-benar dipanggil
 * dengan source itu oleh kode manapun) — tidak perlu kind baru di
 * stock_movements, beda dari farmasi yang perlu menambah kind
 * 'retur-keluar' karena skema kind-nya lebih sempit (masuk/keluar/opname
 * saja, source yang membedakan alasannya).
 */
return new class extends Migration
{
    private const S = 'inventory';

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

    /** ipsrs_pengadaan_barang (DlgPembelian-nya inventory). surat_pemesanan_non_medis = cetak dari data ini. */
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

            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".purchase_orders ADD CONSTRAINT purchase_orders_status_check
            CHECK (status IN ('draf','dipesan','diterima-sebagian','diterima','dibatalkan'))");

        Schema::create(self::S . '.purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->string('item_name', 200)->comment('Disalin saat PO dibuat');
            $table->string('unit_of_measure', 20);
            $table->decimal('quantity_ordered', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->index('purchase_order_id');
        });
    }

    /** penerimaan_non_medis + verifikasi_penerimaan_logistik. Stok masuk lewat StockLedger::receive() (source='pembelian') yang sudah ada, dipanggil dari sini bukan dari MasterDataController::receive() manual. */
    private function createGoodsReceipts(): void
    {
        Schema::create(self::S . '.goods_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('receipt_number', 24)->unique();
            $table->foreignId('purchase_order_id')->constrained(self::S . '.purchase_orders');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('received_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->string('status', 20)->default('diterima')->comment('diterima, terverifikasi');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_outcome', 20)->nullable()->comment('sesuai, tidak-sesuai');
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

    /** ipsrs_returbeli — lewat StockLedger::issue() (source='retur-suplier') yang sudah ada, belum pernah dipanggil kode manapun sebelum ini. */
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
            $table->foreignId('item_id')->constrained(self::S . '.items');
            $table->decimal('quantity', 12, 2);
            $table->string('note', 255)->nullable();
            $table->index('supplier_return_id');
        });
    }
};
