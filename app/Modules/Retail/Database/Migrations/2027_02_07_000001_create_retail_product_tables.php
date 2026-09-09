<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produk, harga & stok toko (domain S item A) — konteks baru `retail`.
 *
 * Tujuh kode: jenis barang, suplier, barang toko, stok opname, riwayat
 * barang, dan dua kode sirkulasi.
 *
 * INI IMPLEMENTASI KETIGA DARI MEKANISME YANG SAMA, DAN ITU DISENGAJA.
 *
 * `pharmacy` dan `inventory` sudah punya rantai yang bentuknya nyaris
 * identik: master barang, buku besar stok, opname, pengadaan, retur.
 * Toko adalah yang ketiga. Menggabungkannya jadi satu tabel akan
 * melahirkan kesalahan yang jauh lebih mahal daripada duplikasi ini:
 * permintaan bangsal bisa menarik stok dari barang dagangan koperasi,
 * dan obat bisa terjual di kasir toko. Batas konteks proyek ini justru
 * ada untuk mencegah itu.
 *
 * Yang perlu dicatat untuk yang datang sesudah: kalau muncul instansi
 * KEEMPAT, menyari mekanisme buku besar stok jadi satu komponen bersama
 * (yang tetap menulis ke schema masing-masing konteks) menjadi pilihan
 * yang lebih murah daripada menyalinnya sekali lagi.
 *
 * STOK TIDAK DISIMPAN SEBAGAI KOLOM. `tokobarang.stok` Khanza adalah
 * saldo berjalan yang menempel pada barangnya. Saldo tanpa buku besar
 * tidak bisa direkonsiliasi: begitu satu transaksi gagal di tengah,
 * angkanya melenceng dan tidak ada cara menelusuri sejak kapan maupun
 * karena apa. Yang tersisa cuma menimpanya dengan hasil hitung fisik —
 * yang berarti selisihnya hilang bersama sebabnya. Di sini stok DIHITUNG
 * dari `stock_movements`, pola yang sama dengan dua konteks sebelumnya.
 *
 * HARGA JUAL JADI BARIS, BUKAN TIGA KOLOM. Khanza menetapkan tepat tiga
 * tingkat harga sebagai kolom pada barangnya: distributor, grosir,
 * retail. Koperasi rumah sakit hampir selalu punya tingkat keempat —
 * harga karyawan — dan tiga kolom tetap tidak bisa menyatakannya tanpa
 * migrasi. Lebih buruk lagi, kolom keempat yang ditambahkan belakangan
 * akan kosong pada seluruh barang lama dan terbaca sebagai "gratis".
 *
 * PATOKAN MARJIN BERVERSI, TIDAK DITIMPA. `tokosetharga` Khanza satu
 * baris tanpa kunci sama sekali: mengubah patokan marjin MENIMPA yang
 * lama, dan pertanyaan "patokan mana yang berlaku waktu barang ini
 * dihargai" tidak punya jawaban. Sama persis dengan pengaturan
 * peminjaman pada domain Q, dan diperlakukan sama.
 *
 * SELISIH OPNAME DIHITUNG, TIDAK DISIMPAN. `tokoopname.selisih` dan
 * `nomihilang` adalah nilai turunan yang dibekukan — dan nilai turunan
 * yang dibekukan akan salah begitu ada koreksi pada hitungan fisiknya,
 * tanpa ada yang tahu kapan. Yang disimpan cuma dua angka yang
 * benar-benar dicatat orang: stok menurut sistem saat itu, dan hasil
 * hitung fisiknya.
 */
return new class extends Migration
{
    private const S = 'retail';

    /**
     * Jenis pergerakan stok. Vocabulari tertutup: tiap pergerakan harus
     * bisa dijelaskan asalnya, dan "penyesuaian" tanpa sebab adalah pintu
     * yang paling mudah dipakai menutupi kehilangan.
     */
    private const PERGERAKAN = [
        'masuk',        // penerimaan dari suplier
        'keluar',       // penjualan
        'retur-masuk',  // retur dari pembeli
        'retur-keluar', // retur ke suplier
        'koreksi',      // hasil stok opname
        'rusak',
        'hilang',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::S);

        Schema::create(self::S.'.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        // ------------------------------------------------------ master

        Schema::create(self::S.'.categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create(self::S.'.suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('address', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('contact_person', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // ----------------------------------------------------- produk

        Schema::create(self::S.'.products', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->unsignedBigInteger('category_id')->nullable();

            /*
             * Satuan sebagai teks, bukan tabel bersama. `satuan_barang`
             * Khanza dipakai lintas domain D/E/F/S, tapi katalog proyek ini
             * sudah menetapkannya milik pharmacy — dan konteks retail tidak
             * boleh membaca schema pharmacy. Konteks inventory memutuskan
             * hal yang sama sebelumnya; kosakata satuan yang sama tidak
             * cukup jadi alasan menyatukan dua konteks yang stoknya memang
             * harus terpisah.
             */
            $table->string('unit', 30)->default('pcs');

            // Harga pokok terakhir, untuk dasar penetapan harga jual.
            // Bukan HPP penjualan: itu dibekukan per baris penjualan.
            $table->decimal('base_cost', 14, 2)->default(0);

            $table->unsignedSmallInteger('minimum_stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category_id', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.'.products
            ADD CONSTRAINT products_category_fk
            FOREIGN KEY (category_id) REFERENCES '.self::S.'.categories (id)');

        // ------------------------------------------- tingkat harga jual

        Schema::create(self::S.'.price_tiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create(self::S.'.product_prices', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('product_id')->constrained(self::S.'.products')->cascadeOnDelete();
            $table->unsignedBigInteger('price_tier_id');
            $table->decimal('price', 14, 2);

            $table->timestampsTz();

            $table->unique(['product_id', 'price_tier_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.product_prices
            ADD CONSTRAINT product_prices_tier_fk
            FOREIGN KEY (price_tier_id) REFERENCES '.self::S.'.price_tiers (id)');

        // Harga jual negatif bukan diskon, ia salah ketik — dan salah ketik
        // yang lolos akan tampil sebagai keuntungan negatif tanpa sebab.
        DB::statement('ALTER TABLE '.self::S.'.product_prices
            ADD CONSTRAINT product_prices_price_check CHECK (price >= 0)');

        // ------------------------------------------- patokan marjin

        Schema::create(self::S.'.pricing_policies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('price_tier_id');
            $table->decimal('markup_percent', 6, 2)->comment('Marjin di atas harga pokok, dalam persen');

            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.'.pricing_policies
            ADD CONSTRAINT pricing_policies_tier_fk
            FOREIGN KEY (price_tier_id) REFERENCES '.self::S.'.price_tiers (id)');

        // Satu patokan aktif per tingkat harga; yang lama dinonaktifkan,
        // tidak ditimpa.
        DB::statement('CREATE UNIQUE INDEX pricing_policy_aktif_unique
            ON '.self::S.'.pricing_policies (price_tier_id) WHERE is_active');

        // ------------------------------------------ buku besar stok

        Schema::create(self::S.'.stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('product_id');
            $table->string('kind', 20);

            /*
             * Bertanda: positif menambah, negatif mengurangi. Arah TIDAK
             * diserahkan pada pemanggil — service menetapkannya dari
             * jenisnya, sebagaimana arah kas pada finance dan arah cairan
             * pada clinical.
             */
            $table->integer('quantity');

            $table->decimal('unit_cost', 14, 2)->nullable()->comment('Harga pokok saat pergerakan ini');
            $table->string('reference', 40)->nullable()->comment('Nomor nota/dokumen sumbernya');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['product_id', 'occurred_at']);
            $table->index('kind');
        });

        DB::statement('ALTER TABLE '.self::S.'.stock_movements
            ADD CONSTRAINT stock_movements_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('".implode("','", self::PERGERAKAN)."'))");

        // Pergerakan bernilai nol tidak mengubah apa pun tapi mengotori
        // riwayat, dan riwayat yang berisi baris tanpa akibat membuat
        // penelusuran selisih jadi jauh lebih lama.
        DB::statement('ALTER TABLE '.self::S.'.stock_movements
            ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity <> 0)');

        // ------------------------------------------------ stok opname

        Schema::create(self::S.'.stock_opnames', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('opname_number', 24)->unique();
            $table->date('counted_on');
            $table->string('status', 20)->default('berjalan');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('counted_by')->nullable();
            $table->string('counted_by_name', 150)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.".stock_opnames ADD CONSTRAINT stock_opnames_status_check
            CHECK (status IN ('berjalan','selesai','dibatalkan'))");

        Schema::create(self::S.'.stock_opname_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('opname_id')->constrained(self::S.'.stock_opnames')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');

            /*
             * DUA ANGKA YANG BENAR-BENAR DICATAT ORANG, dan tidak lebih.
             * Selisih serta nilai rupiahnya DIHITUNG — `tokoopname.selisih`
             * dan `nomihilang` Khanza adalah nilai turunan yang dibekukan,
             * dan nilai turunan yang dibekukan akan salah begitu hitungan
             * fisiknya dikoreksi, tanpa ada yang tahu kapan.
             */
            $table->integer('system_quantity')->comment('Stok menurut buku besar saat dihitung');
            $table->integer('counted_quantity')->nullable()->comment('Hasil hitung fisik; kosong berarti belum dihitung');

            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['opname_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.stock_opname_items
            ADD CONSTRAINT stock_opname_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        // Hitungan fisik negatif tidak mungkin; nol adalah jawaban yang sah
        // dan justru penting, jadi yang ditolak hanya yang di bawah nol.
        DB::statement('ALTER TABLE '.self::S.'.stock_opname_items
            ADD CONSTRAINT stock_opname_items_counted_check
            CHECK (counted_quantity IS NULL OR counted_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.stock_opname_items');
        Schema::dropIfExists(self::S.'.stock_opnames');
        Schema::dropIfExists(self::S.'.stock_movements');
        Schema::dropIfExists(self::S.'.pricing_policies');
        Schema::dropIfExists(self::S.'.product_prices');
        Schema::dropIfExists(self::S.'.price_tiers');
        Schema::dropIfExists(self::S.'.products');
        Schema::dropIfExists(self::S.'.suppliers');
        Schema::dropIfExists(self::S.'.categories');
        Schema::dropIfExists(self::S.'.number_sequences');

        DB::statement('DROP SCHEMA IF EXISTS '.self::S);
    }
};
