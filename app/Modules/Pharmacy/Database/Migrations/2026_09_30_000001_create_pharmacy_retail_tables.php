<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain D Khanza item 5 dari 6 sub-order yang disepakati — retail,
 * retur, dan untung. 15 kode, semua context "pharmacy" di katalog
 * (dikonfirmasi lewat Khanza_Functional_Dependency_Map.xlsx) — termasuk
 * asal_hibah yang paket Java-nya "inventaris" (bukan "inventory" seperti
 * 14 kode lain) dan kodenya dipakai ulang di domain D/E/F/G, sama
 * kuirk-nya dengan satuan_barang di item 1 — katalog tetap tag
 * "pharmacy" untuk kemunculan domain D ini, jadi tetap dibangun di sini.
 *
 * Desain dikonfirmasi user (AskUserQuestion) sebelum dibangun:
 *
 *  - penjualan_obat (DlgPenjualan, jual bebas TANPA resep — beda dari
 *    resep_luar yang wajib ada resep) dan piutang_obat (DlgPiutang,
 *    jual kredit) DIGABUNG satu tabel retail_sales, dibedakan lewat
 *    payment_status ('lunas'/'piutang') — bukan konsep yang cukup beda
 *    untuk jadi dua tabel terpisah, cuma cara bayarnya.
 *  - retur_dari_pembeli (DlgReturJual) dan retur_piutang_pasien
 *    (DlgReturPiutang, retur atas penjualan kredit) jadi SATU mekanisme
 *    retur atas retail_sales yang sama (retail_sale_returns) — pola
 *    sama dengan supplier_returns di item 2.
 *  - retur_obat_ranap (DlgReturObatPasien, obat rawat inap yang tak
 *    terpakai dikembalikan) tabel terpisah (patient_drug_returns) —
 *    terikat registrasi pasien, bukan penjualan retail.
 *  - hibah_obat_bhp (InventoryHibahObatBHP) + asal_hibah
 *    (InventarisAsalHibah) jadi donors (master pemberi hibah) +
 *    donation_receipts(+items) — menambah stok lewat StockLedger::receive()
 *    kind baru 'hibah' (beda dari 'masuk' pembelian, supaya laporan
 *    nilai pengadaan tidak ikut menghitung barang yang gratis).
 *  - 3 kode keuntungan_* (keuntungan_penjualan, keuntungan_beri_obat,
 *    keuntungan_beri_obat_nonpiutang) dan 6 kode ringkasan_*
 *    (ringkasan_penjualan_obat, ringkasan_retur_pembeli_obat,
 *    ringkasan_piutang_obat, ringkasan_stok_keluar_obat,
 *    ringkasan_beri_obat, ringkasan_hibah_obat) tidak jadi tabel/layar
 *    sendiri — dilebur satu layar rekap (item 5 juga, PharmacyRecapController),
 *    baca retail_sales/prescriptions/stock_movements yang sudah ada.
 *
 * retail_sale_items menyimpan cost_price SNAPSHOT (pola sama dengan
 * order_items/drug_units/dst.) supaya laporan untung tidak berubah
 * kalau harga pokok berubah belakangan.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        $this->createRetailSales();
        $this->createPatientDrugReturns();
        $this->createDonors();
        $this->widenStockMovementsKind();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.stock_movements DROP CONSTRAINT stock_movements_kind_check');
        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','retur-keluar','koreksi','kadaluarsa','rusak','mutasi-masuk','mutasi-keluar'))");

        Schema::dropIfExists(self::S . '.donation_receipt_items');
        Schema::dropIfExists(self::S . '.donation_receipts');
        Schema::dropIfExists(self::S . '.donors');
        Schema::dropIfExists(self::S . '.patient_drug_return_items');
        Schema::dropIfExists(self::S . '.patient_drug_returns');
        Schema::dropIfExists(self::S . '.retail_sale_return_items');
        Schema::dropIfExists(self::S . '.retail_sale_returns');
        Schema::dropIfExists(self::S . '.retail_sale_items');
        Schema::dropIfExists(self::S . '.retail_sales');
    }

    /** penjualan_obat + piutang_obat. */
    private function createRetailSales(): void
    {
        Schema::create(self::S . '.retail_sales', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('sale_number', 24)->unique();
            $table->string('customer_name', 150);
            $table->string('customer_identity_number', 40)->nullable();

            $table->string('payment_status', 20)->default('lunas')->comment('lunas, piutang');
            $table->string('status', 20)->default('selesai')->comment('selesai, retur-sebagian, retur-penuh, dibatalkan');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->timestampTz('sold_at');
            $table->unsignedBigInteger('sold_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->index(['payment_status', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".retail_sales ADD CONSTRAINT retail_sales_payment_status_check
            CHECK (payment_status IN ('lunas','piutang'))");
        DB::statement("ALTER TABLE " . self::S . ".retail_sales ADD CONSTRAINT retail_sales_status_check
            CHECK (status IN ('selesai','retur-sebagian','retur-penuh','dibatalkan'))");

        Schema::create(self::S . '.retail_sale_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('sale_id')->constrained(self::S . '.retail_sales')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0)->comment('Snapshot HPP saat dijual, dasar laporan untung');
            $table->decimal('quantity_returned', 12, 2)->default(0);
            $table->index('sale_id');
        });

        /** retur_dari_pembeli + retur_piutang_pasien — satu mekanisme retur, terlepas lunas/piutang. */
        Schema::create(self::S . '.retail_sale_returns', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('return_number', 24)->unique();
            $table->foreignId('sale_id')->constrained(self::S . '.retail_sales');
            $table->text('reason');
            $table->timestampTz('returned_at');
            $table->unsignedBigInteger('returned_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();
        });

        Schema::create(self::S . '.retail_sale_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('sale_return_id')->constrained(self::S . '.retail_sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained(self::S . '.retail_sale_items');
            $table->decimal('quantity', 12, 2);
            $table->index('sale_return_id');
        });
    }

    /** retur_obat_ranap — obat rawat inap tak terpakai dikembalikan, terikat registrasi pasien. */
    private function createPatientDrugReturns(): void
    {
        Schema::create(self::S . '.patient_drug_returns', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('return_number', 24)->unique();
            $table->unsignedBigInteger('registration_id')->comment('ID encounter.registrations, referensi longgar');
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->timestampTz('returned_at');
            $table->unsignedBigInteger('returned_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->index('registration_id');
        });

        Schema::create(self::S . '.patient_drug_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('return_id')->constrained(self::S . '.patient_drug_returns')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200);
            $table->string('batch_number', 40)->nullable()->comment('Kalau diketahui — kalau tidak, masuk batch baru bertanda retur');
            $table->decimal('quantity', 12, 2);
            $table->index('return_id');
        });
    }

    /** hibah_obat_bhp + asal_hibah. */
    private function createDonors(): void
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
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('drug_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->string('batch_number', 40);
            $table->date('expiry_date')->nullable();
            $table->index('donation_receipt_id');
        });
    }

    /** hibah_obat_bhp butuh kind baru: 'hibah' (stok masuk gratis, beda dari 'masuk' pembelian supaya laporan nilai pengadaan tidak ikut menghitung barang gratis). */
    private function widenStockMovementsKind(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.stock_movements DROP CONSTRAINT stock_movements_kind_check');
        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','retur-keluar','koreksi','kadaluarsa','rusak','mutasi-masuk','mutasi-keluar','hibah'))");
    }
};
