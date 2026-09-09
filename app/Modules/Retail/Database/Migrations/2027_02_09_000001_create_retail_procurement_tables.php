<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengadaan & hutang toko (domain S item B).
 *
 * Tujuh kode: pengajuan barang, surat pemesanan, pengadaan barang,
 * penerimaan barang, retur ke suplier, hutang toko, dan bayar pesan toko.
 *
 * SURAT PEMESANAN BUKAN ENTITAS TERSENDIRI. `toko_surat_pemesanan`
 * Khanza punya tabelnya sendiri berikut detailnya, terpisah dari
 * `tokopembelian`. Dua tabel untuk satu pesanan berarti dua tempat yang
 * harus sepakat tentang barang dan jumlah yang sama — dan begitu keduanya
 * berbeda, tidak ada cara menentukan mana yang dikirim ke suplier. Di
 * sini surat pemesanan adalah TAMPILAN CETAK dari pesanan yang sama,
 * pola yang persis dipakai `pemesanan_obat` pada domain D.
 *
 * HUTANG DIHITUNG, TIDAK DISIMPAN. `toko_hutang` Khanza adalah layar
 * tersendiri; di sini hutang adalah selisih antara nilai penerimaan dan
 * yang sudah dibayar atasnya. Saldo hutang yang disimpan sebagai kolom
 * akan melenceng begitu satu pembayaran gagal di tengah, dan yang
 * tertinggal cuma angka yang tidak bisa ditelusuri ke nota mana pun.
 *
 * PENERIMAAN TIDAK BOLEH MELEBIHI PESANAN. Barang yang datang lebih
 * banyak daripada yang dipesan bukan kelebihan yang menyenangkan: ia
 * berarti pesanannya salah dicatat, atau ada kiriman yang tidak pernah
 * dipesan siapa pun. Keduanya harus berhenti di meja penerimaan, bukan
 * masuk diam-diam ke stok lalu ditagihkan.
 *
 * HARGA POKOK BARANG DIPERBARUI DARI PENERIMAAN TERAKHIR, dan itu
 * DISENGAJA berbeda dari HPP penjualan. Harga pokok pada barang dipakai
 * untuk mengusulkan harga jual berikutnya; HPP yang dipakai menghitung
 * keuntungan dibekukan per baris penjualan saat transaksi. Menyatukan
 * keduanya membuat keuntungan penjualan bulan lalu berubah setiap kali
 * ada penerimaan baru.
 */
return new class extends Migration
{
    private const S = 'retail';

    private const STATUS_PENGAJUAN = ['diajukan', 'disetujui', 'ditolak', 'diproses'];

    private const STATUS_PO = ['draft', 'dikirim', 'sebagian', 'diterima', 'dibatalkan'];

    private const STATUS_BAYAR = ['belum', 'sebagian', 'lunas'];

    public function up(): void
    {
        // ---------------------------------------------------- pengajuan

        Schema::create(self::S.'.requisitions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('requisition_number', 24)->unique();
            $table->date('requested_on');
            $table->string('requested_by_name', 150);
            $table->text('purpose')->nullable();

            $table->string('status', 20)->default('diajukan');
            $table->text('decision_note')->nullable();
            $table->string('decided_by_name', 150)->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement('ALTER TABLE '.self::S.".requisitions ADD CONSTRAINT requisitions_status_check
            CHECK (status IN ('".implode("','", self::STATUS_PENGAJUAN)."'))");

        /*
         * Penolakan wajib beralasan; persetujuan tidak. Bentuk
         * ketaksimetrisan yang sama dengan penolakan permintaan pasien pada
         * domain P: pengajuan yang disetujui berbukti pada pesanan yang
         * lahir sesudahnya, yang ditolak tidak meninggalkan apa pun selain
         * catatan ini.
         */
        DB::statement('ALTER TABLE '.self::S.".requisitions ADD CONSTRAINT requisitions_rejection_check
            CHECK (status <> 'ditolak' OR (decision_note IS NOT NULL AND btrim(decision_note) <> ''))");

        Schema::create(self::S.'.requisition_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('requisition_id')->constrained(self::S.'.requisitions')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['requisition_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.requisition_items
            ADD CONSTRAINT requisition_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.'.requisition_items
            ADD CONSTRAINT requisition_items_quantity_check CHECK (quantity > 0)');

        // ------------------------------------------------------ pesanan

        Schema::create(self::S.'.purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('order_number', 24)->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('requisition_id')->nullable()->comment('Pengajuan yang mendasarinya, bila ada');

            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->index(['supplier_id', 'status']);
        });

        foreach (['supplier_id' => 'suppliers', 'requisition_id' => 'requisitions'] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.purchase_orders
                ADD CONSTRAINT purchase_orders_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        DB::statement('ALTER TABLE '.self::S.".purchase_orders ADD CONSTRAINT purchase_orders_status_check
            CHECK (status IN ('".implode("','", self::STATUS_PO)."'))");

        Schema::create(self::S.'.purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('order_id')->constrained(self::S.'.purchase_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->timestampsTz();

            $table->unique(['order_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.purchase_order_items
            ADD CONSTRAINT purchase_order_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.'.purchase_order_items
            ADD CONSTRAINT purchase_order_items_quantity_check CHECK (quantity > 0)');

        // --------------------------------------------------- penerimaan

        Schema::create(self::S.'.goods_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('receipt_number', 24)->unique();
            $table->unsignedBigInteger('order_id');
            $table->date('received_on');
            $table->string('supplier_invoice_number', 60)->nullable();

            $table->decimal('total_amount', 16, 2)->default(0);
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->string('payment_status', 20)->default('belum');
            $table->date('due_on')->nullable()->comment('Jatuh tempo pembayaran ke suplier');

            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['payment_status', 'due_on']);
        });

        DB::statement('ALTER TABLE '.self::S.'.goods_receipts
            ADD CONSTRAINT goods_receipts_order_fk
            FOREIGN KEY (order_id) REFERENCES '.self::S.'.purchase_orders (id)');

        DB::statement('ALTER TABLE '.self::S.".goods_receipts ADD CONSTRAINT goods_receipts_payment_status_check
            CHECK (payment_status IN ('".implode("','", self::STATUS_BAYAR)."'))");

        /*
         * Dibayar lebih besar daripada nilainya bukan kelebihan bayar yang
         * bisa diabaikan: ia berarti nota lain ikut terbayar di sini tanpa
         * tercatat, dan hutang atas nota itu akan tampak masih terbuka.
         */
        DB::statement('ALTER TABLE '.self::S.'.goods_receipts
            ADD CONSTRAINT goods_receipts_paid_check
            CHECK (paid_amount >= 0 AND paid_amount <= total_amount)');

        Schema::create(self::S.'.goods_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('receipt_id')->constrained(self::S.'.goods_receipts')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->timestampsTz();

            $table->unique(['receipt_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.goods_receipt_items
            ADD CONSTRAINT goods_receipt_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.'.goods_receipt_items
            ADD CONSTRAINT goods_receipt_items_quantity_check CHECK (quantity > 0)');

        // ------------------------------------------ retur ke suplier

        Schema::create(self::S.'.supplier_returns', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('return_number', 24)->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('receipt_id')->nullable()->comment('Penerimaan yang diretur, bila diketahui');

            $table->date('returned_on');

            // Alasan WAJIB: retur ke suplier tanpa alasan tidak bisa
            // dibedakan dari barang yang hilang lalu dicatat sebagai retur.
            $table->text('reason');

            $table->decimal('total_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
        });

        foreach (['supplier_id' => 'suppliers', 'receipt_id' => 'goods_receipts'] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.supplier_returns
                ADD CONSTRAINT supplier_returns_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        DB::statement('ALTER TABLE '.self::S.".supplier_returns ADD CONSTRAINT supplier_returns_reason_check
            CHECK (btrim(reason) <> '')");

        Schema::create(self::S.'.supplier_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('return_id')->constrained(self::S.'.supplier_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->timestampsTz();

            $table->unique(['return_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.supplier_return_items
            ADD CONSTRAINT supplier_return_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.'.supplier_return_items
            ADD CONSTRAINT supplier_return_items_quantity_check CHECK (quantity > 0)');

        // ------------------------------------------ pembayaran hutang

        Schema::create(self::S.'.supplier_payments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('payment_number', 24)->unique();
            $table->unsignedBigInteger('receipt_id');
            $table->date('paid_on');
            $table->decimal('amount', 16, 2);
            $table->string('method', 30)->default('tunai');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->index('receipt_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.supplier_payments
            ADD CONSTRAINT supplier_payments_receipt_fk
            FOREIGN KEY (receipt_id) REFERENCES '.self::S.'.goods_receipts (id)');

        // Pembayaran nol atau negatif bukan pembayaran; ia baris yang
        // mengotori riwayat tanpa mengubah apa pun.
        DB::statement('ALTER TABLE '.self::S.'.supplier_payments
            ADD CONSTRAINT supplier_payments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.supplier_payments');
        Schema::dropIfExists(self::S.'.supplier_return_items');
        Schema::dropIfExists(self::S.'.supplier_returns');
        Schema::dropIfExists(self::S.'.goods_receipt_items');
        Schema::dropIfExists(self::S.'.goods_receipts');
        Schema::dropIfExists(self::S.'.purchase_order_items');
        Schema::dropIfExists(self::S.'.purchase_orders');
        Schema::dropIfExists(self::S.'.requisition_items');
        Schema::dropIfExists(self::S.'.requisitions');
    }
};
