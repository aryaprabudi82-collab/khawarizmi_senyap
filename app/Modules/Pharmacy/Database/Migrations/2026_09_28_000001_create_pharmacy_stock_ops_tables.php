<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain D Khanza item 3 dari 6 sub-order yang disepakati — stok & batch
 * ops. 15 kode, semua context "pharmacy" di katalog (dikonfirmasi lewat
 * Khanza_Functional_Dependency_Map.xlsx, tidak ada salah-taut).
 *
 * Desain dikonfirmasi user (AskUserQuestion) sebelum dibangun:
 *
 *  - stok_opname_obat (DlgInputStok) dan mutasi_barang (DlgMutasiBarang)
 *    genuinely transaksi baru — tabel di migrasi ini.
 *  - ppn_obat (DlgCariPPNObat) cukup kolom vat_rate baru di drugs
 *    (perluasan layar master item 1, bukan layar/tabel sendiri).
 *  - 12 kode sisanya (data_batch, riwayat_data_batch, kadaluarsa_batch,
 *    sisa_stok, darurat_stok, obat_bhp_tidakbergerak,
 *    stok_akhir_farmasi_pertanggal, sirkulasi_obat s/d sirkulasi_obat6)
 *    semuanya laporan/browsing atas pharmacy.stock_batches &
 *    pharmacy.stock_movements yang SUDAH ADA sejak migrasi resep awal —
 *    digabung SATU layar "Laporan Stok Farmasi" (StockReportController,
 *    item ini juga), tidak ada tabel baru untuknya di sini.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        $this->createStockOpnames();
        $this->createStockTransfers();
        $this->widenDrugsVat();
        $this->widenStockMovementsKind();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.stock_movements DROP CONSTRAINT stock_movements_kind_check');
        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','retur-keluar','koreksi','kadaluarsa','rusak'))");

        Schema::table(self::S . '.drugs', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });

        Schema::dropIfExists(self::S . '.stock_transfer_items');
        Schema::dropIfExists(self::S . '.stock_transfers');
        Schema::dropIfExists(self::S . '.stock_opname_items');
        Schema::dropIfExists(self::S . '.stock_opnames');
    }

    /** stok_opname_obat — rekonsiliasi stok sistem vs hitung fisik per lokasi, selisih dicatat sebagai movement 'koreksi'. */
    private function createStockOpnames(): void
    {
        Schema::create(self::S . '.stock_opnames', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('opname_number', 24)->unique();
            $table->foreignId('location_id')->constrained(self::S . '.stock_locations');

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
            $table->foreignId('batch_id')->constrained(self::S . '.stock_batches');

            $table->decimal('system_quantity', 12, 2)->comment('Saldo sistem saat opname dibuat, snapshot — bukan dibaca ulang saat selesai');
            $table->decimal('counted_quantity', 12, 2)->nullable();
            $table->string('note', 255)->nullable();

            $table->index('opname_id');
        });
    }

    /** mutasi_barang — transfer stok antar lokasi (mis. GUDANG -> DEPO-RJ), satu-satunya jalan barang hasil pengadaan sampai ke depo pelayanan. */
    private function createStockTransfers(): void
    {
        Schema::create(self::S . '.stock_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('transfer_number', 24)->unique();
            $table->foreignId('from_location_id')->constrained(self::S . '.stock_locations');
            $table->foreignId('to_location_id')->constrained(self::S . '.stock_locations');

            $table->timestampTz('transferred_at');
            $table->unsignedBigInteger('transferred_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".stock_transfers ADD CONSTRAINT stock_transfers_location_check
            CHECK (from_location_id <> to_location_id)");

        Schema::create(self::S . '.stock_transfer_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('transfer_id')->constrained(self::S . '.stock_transfers')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');
            $table->string('batch_number', 40);
            $table->decimal('quantity', 12, 2);
            $table->index('transfer_id');
        });
    }

    /** ppn_obat — tarif PPN per obat/alkes/BHP, null berarti bebas PPN (banyak obat esensial). */
    private function widenDrugsVat(): void
    {
        Schema::table(self::S . '.drugs', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('sell_price')->comment('Persentase PPN, null = bebas PPN');
        });

        DB::statement("ALTER TABLE " . self::S . ".drugs ADD CONSTRAINT drugs_vat_rate_check
            CHECK (vat_rate IS NULL OR (vat_rate >= 0 AND vat_rate <= 100))");
    }

    /** mutasi_barang butuh dua kind baru: 'mutasi-keluar' (sisi asal) dan 'mutasi-masuk' (sisi tujuan) — beda dari 'masuk'/'keluar' biasa supaya rekonsiliasi tidak salah kira ini penerimaan/dispensing sungguhan. */
    private function widenStockMovementsKind(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.stock_movements DROP CONSTRAINT stock_movements_kind_check');
        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','retur-keluar','koreksi','kadaluarsa','rusak','mutasi-masuk','mutasi-keluar'))");
    }
};
