<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charge Description Master (CDM) — Modul A, Wave 1.
 *
 * SATU KODE ITEM GLOBAL untuk SELURUH yang bisa ditagihkan: tindakan,
 * layanan penunjang, obat, BHP, alkes, akomodasi kamar, visite, parkir,
 * dan barang koperasi. Sebelum ini, "apa saja yang bisa ditagihkan"
 * tersebar di enam tabel pada lima konteks, dan tidak ada satu tempat pun
 * yang bisa menjawabnya.
 *
 * YANG TIDAK DILAKUKAN MIGRASI INI, DAN ITU DISENGAJA.
 *
 * Ia TIDAK menggantikan `catalog.tariffs`. Discovery awal saya menyimpulkan
 * tarif layanan harus di-merge ke CDM baru karena "tidak bitemporal" —
 * dan kesimpulan itu SALAH: `catalog.tariffs` sudah punya valid_from/
 * valid_until, sudah berdimensi penjamin dan kelas, resolusinya per tanggal
 * transaksi sudah berjalan, dan komponen jasanya dijaga CHECK. Menggantinya
 * berarti membuang mekanisme yang sudah bekerja, sudah diuji, dan dipakai
 * billing setiap hari — persis yang dilarang Aturan Konsolidasi.
 *
 * Maka CDM di sini berperan sebagai KATALOG PENAUT, bukan pengganti:
 *
 *   - Ia memberi KODE GLOBAL untuk tiap item yang bisa ditagihkan.
 *   - Ia MENUNJUK ke sumber tarifnya (catalog.tariffs, pharmacy.drugs,
 *     inpatient.rooms, dst.) lewat source_context + source_id.
 *   - Ia memegang PEMETAAN KE AKUN COA — inilah yang benar-benar belum
 *     ada di mana pun, dan justru inilah yang membuat pendapatan tidak
 *     bisa dijurnalkan otomatis.
 *
 * ITEM TANPA PEMETAAN AKUN TIDAK BOLEH DIAKTIFKAN. Ini aturan kritis
 * Modul A, dan ditegakkan CHECK di basis data — bukan hanya di aplikasi.
 * Item aktif tanpa akun berarti ada pendapatan yang tertagih tapi tidak
 * pernah sampai ke buku besar, dan selisihnya baru ketahuan saat ada yang
 * menutup buku.
 *
 * KODE TIDAK PERNAH DIHAPUS, hanya di-expire. Kode yang dipakai ulang
 * membuat tagihan tahun lalu menunjuk barang yang sama sekali lain.
 */
return new class extends Migration
{
    private const S = 'keuangan_master';

    /** Konteks asal tarif yang sah — dipakai CHECK supaya tidak ada rujukan liar. */
    private const SUMBER = [
        'catalog',    // tindakan & layanan klinis  -> catalog.tariffs
        'pharmacy',   // obat, BHP, alkes           -> pharmacy.drugs + drug_markups
        'inpatient',  // akomodasi kamar            -> inpatient.rooms.daily_rate
        'retail',     // barang koperasi            -> retail.product_prices
        'parking',    // parkir                     -> parking.rates
        'envlab',     // uji lab kesehatan lingkungan
        'manual',     // item yang tarifnya di CDM sendiri (administrasi, materai)
    ];

    /** Golongan item, menentukan bentuk penagihannya. */
    private const GOLONGAN = [
        'tindakan', 'penunjang', 'obat', 'bhp', 'alkes',
        'akomodasi', 'visite', 'administrasi', 'paket', 'lain',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::S);

        Schema::create(self::S.'.charge_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * KODE GLOBAL, unik di seluruh rumah sakit. Satu item = satu
             * kode, apa pun konteks asalnya. Inilah yang dipakai billing,
             * klaim, dan costing untuk menyebut hal yang sama.
             */
            $table->string('code', 40)->unique();
            $table->string('name', 200);
            $table->string('golongan', 20);

            /* Ke mana tarifnya dicari. */
            $table->string('source_context', 20);
            $table->unsignedBigInteger('source_id')->nullable()
                ->comment('ID baris di konteks asal; null untuk golongan manual');

            /*
             * PEMETAAN AKUN — bagian yang benar-benar baru, dan alasan
             * utama CDM ini ada.
             *
             * revenue_account_id : akun pendapatan saat item ditagihkan
             * cogs_account_id    : akun beban pokok (obat/BHP), null untuk jasa
             * discount_account_id: akun potongan, null berarti memakai lawan
             *                      dari akun pendapatannya
             */
            $table->unsignedBigInteger('revenue_account_id')->nullable()
                ->comment('finance.chart_of_accounts, referensi longgar lintas konteks');
            $table->unsignedBigInteger('cogs_account_id')->nullable();
            $table->unsignedBigInteger('discount_account_id')->nullable();

            /* Dimensi bawaan, bisa ditimpa saat transaksi. */
            $table->string('default_program', 20)->nullable()
                ->comment('pelayanan/pendidikan/penelitian — dimensi PTN-BH');
            $table->unsignedBigInteger('default_unit_id')->nullable();

            /* Perpajakan, disiapkan untuk Wave 5. */
            $table->boolean('is_taxable')->default(false);
            $table->string('tax_code', 20)->nullable();

            $table->boolean('is_active')->default(false)
                ->comment('Lahir NONAKTIF: item tanpa pemetaan akun tidak boleh menagih');

            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->timestampsTz();

            $table->index(['golongan', 'is_active']);
            $table->index(['source_context', 'source_id']);
            $table->index('revenue_account_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.charge_items ADD CONSTRAINT charge_items_golongan_check
            CHECK (golongan IN (\''.implode("','", self::GOLONGAN).'\'))');

        DB::statement('ALTER TABLE '.self::S.'.charge_items ADD CONSTRAINT charge_items_source_check
            CHECK (source_context IN (\''.implode("','", self::SUMBER).'\'))');

        DB::statement('ALTER TABLE '.self::S.'.charge_items ADD CONSTRAINT charge_items_program_check
            CHECK (default_program IS NULL OR default_program IN (\'pelayanan\',\'pendidikan\',\'penelitian\'))');

        /*
         * ATURAN KRITIS MODUL A, DITEGAKKAN BASIS DATA.
         *
         * Item aktif WAJIB punya akun pendapatan. Kalau hanya diperiksa di
         * aplikasi, satu seeder atau satu perbaikan data lewat tinker
         * cukup untuk melahirkan item yang menagih tanpa pernah sampai ke
         * buku besar — dan selisihnya baru ketahuan berbulan-bulan
         * kemudian saat ada yang menutup buku.
         */
        DB::statement('ALTER TABLE '.self::S.'.charge_items ADD CONSTRAINT charge_items_active_needs_account
            CHECK (is_active = false OR revenue_account_id IS NOT NULL)');

        /*
         * Barang berpersediaan (obat, BHP, alkes) yang aktif WAJIB punya
         * akun beban pokok. Tanpa itu, HPP-nya tidak bisa dijurnalkan dan
         * nilai persediaan di GL akan terus melenceng dari gudang.
         */
        DB::statement('ALTER TABLE '.self::S.'.charge_items ADD CONSTRAINT charge_items_stocked_needs_cogs
            CHECK (is_active = false
                   OR golongan NOT IN (\'obat\',\'bhp\',\'alkes\')
                   OR cogs_account_id IS NOT NULL)');

        DB::statement('ALTER TABLE '.self::S.'.charge_items ADD CONSTRAINT charge_items_period_check
            CHECK (valid_until IS NULL OR valid_until >= valid_from)');

        /*
         * Satu baris sumber hanya boleh punya SATU item CDM yang berlaku
         * pada satu waktu. Indeks parsial, karena valid_until NULL berarti
         * "masih berlaku" dan di PostgreSQL NULL tidak sama dengan NULL —
         * indeks unik biasa akan membiarkan dua item berjalan bersamaan
         * untuk sumber yang sama tanpa ada yang menahannya.
         */
        DB::statement('CREATE UNIQUE INDEX charge_items_source_berjalan_unique
            ON '.self::S.'.charge_items (source_context, source_id)
            WHERE valid_until IS NULL AND source_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.charge_items');
        DB::statement('DROP SCHEMA IF EXISTS '.self::S.' CASCADE');
    }
};
