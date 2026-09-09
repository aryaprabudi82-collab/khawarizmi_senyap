<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan, piutang & rekap toko (domain S item C).
 *
 * Sepuluh kode: member, penjualan, retur jual, piutang, retur piutang,
 * bayar piutang, pendapatan harian, penjualan harian, piutang harian, dan
 * keuntungan barang.
 *
 * PENJUALAN TUNAI DAN PIUTANG ADALAH SATU TABEL, BUKAN DUA.
 *
 * Khanza memisahkannya: `tokopenjualan` untuk yang tunai, `tokopiutang`
 * untuk yang kredit, masing-masing dengan tabel detailnya sendiri. Dua
 * tabel untuk satu peristiwa — seseorang membeli barang — berarti setiap
 * laporan penjualan harus menggabungkan keduanya, dan laporan yang lupa
 * salah satunya MENJAWAB DENGAN TENANG dengan angka yang lebih kecil
 * daripada kenyataannya.
 *
 * Bentuk kekurangan yang sama sudah ditemui dua kali sepanjang proyek
 * ini: ebook yang dipisah dari buku pada domain Q, dan sebelum itu
 * penjualan tunai/kredit farmasi yang justru DISATUKAN saat domain D
 * dikerjakan. Di sini keputusannya sama — satu tabel, dibedakan cara
 * bayarnya.
 *
 * HARGA POKOK DIBEKUKAN PER BARIS PENJUALAN. Keuntungan dihitung dari
 * selisih harga jual dan harga pokok SAAT TRANSAKSI. Kalau harga pokok
 * dibaca dari barangnya saat laporan dibuat, keuntungan bulan lalu akan
 * berubah setiap kali ada penerimaan baru dengan harga berbeda — dan
 * laporan keuangan yang angkanya berubah sendiri tidak bisa dipakai
 * menutup buku.
 *
 * SISA PIUTANG DIHITUNG, TIDAK DISIMPAN. `tokopiutang.sisapiutang`
 * Khanza adalah saldo yang menempel pada notanya; saldo tanpa buku
 * pembayaran akan melenceng begitu satu cicilan gagal di tengah, dan yang
 * tertinggal cuma angka yang tidak bisa ditelusuri ke pembayaran mana pun.
 *
 * RETUR PIUTANG MENGURANGI TAGIHAN, BUKAN MENGEMBALIKAN UANG. Retur atas
 * penjualan yang belum dibayar tidak menghasilkan pengeluaran kas — ia
 * memperkecil yang harus ditagih. Menyamakannya dengan retur tunai
 * membuat kas tercatat keluar untuk uang yang belum pernah masuk.
 */
return new class extends Migration
{
    private const S = 'retail';

    private const CARA_BAYAR = ['tunai', 'piutang'];

    private const STATUS_BAYAR = ['lunas', 'sebagian', 'belum'];

    public function up(): void
    {
        // ------------------------------------------------------ member

        Schema::create(self::S.'.members', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('member_number', 20)->unique();
            $table->string('name', 150);
            $table->string('sex', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable();

            $table->unsignedBigInteger('default_price_tier_id')->nullable()
                ->comment('Tingkat harga yang berlaku untuk member ini');

            $table->date('joined_on');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.'.members
            ADD CONSTRAINT members_tier_fk
            FOREIGN KEY (default_price_tier_id) REFERENCES '.self::S.'.price_tiers (id)');

        DB::statement('ALTER TABLE '.self::S.".members ADD CONSTRAINT members_sex_check
            CHECK (sex IS NULL OR sex IN ('L','P'))");

        // --------------------------------------------------- penjualan

        Schema::create(self::S.'.sales', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('sale_number', 24)->unique();
            $table->timestampTz('sold_at');

            $table->unsignedBigInteger('member_id')->nullable();
            // Nama pembeli DISALIN: pembeli tanpa kartu member tetap harus
            // bisa dicatat, dan member yang datanya berubah tidak boleh
            // mengubah bunyi nota yang sudah tercetak.
            $table->string('buyer_name', 150)->nullable();

            $table->unsignedBigInteger('price_tier_id')->nullable();

            /*
             * SATU TABEL UNTUK TUNAI DAN PIUTANG. Khanza memisahkannya jadi
             * dua tabel; laporan penjualan yang lupa salah satunya menjawab
             * dengan tenang dengan angka yang lebih kecil daripada
             * kenyataannya.
             */
            $table->string('payment_type', 20)->default('tunai');

            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);

            $table->decimal('down_payment', 16, 2)->default(0)->comment('Uang muka, hanya untuk piutang');
            $table->date('due_on')->nullable()->comment('Jatuh tempo piutang');
            $table->string('payment_status', 20)->default('lunas');

            $table->unsignedBigInteger('cashier_id')->nullable();
            $table->string('cashier_name', 150)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['payment_type', 'payment_status']);
            $table->index('sold_at');
        });

        foreach (['member_id' => 'members', 'price_tier_id' => 'price_tiers'] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.sales
                ADD CONSTRAINT sales_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        DB::statement('ALTER TABLE '.self::S.".sales ADD CONSTRAINT sales_payment_type_check
            CHECK (payment_type IN ('".implode("','", self::CARA_BAYAR)."'))");

        DB::statement('ALTER TABLE '.self::S.".sales ADD CONSTRAINT sales_payment_status_check
            CHECK (payment_status IN ('".implode("','", self::STATUS_BAYAR)."'))");

        // Penjualan tunai lunas seketika; jatuh tempo dan uang muka hanya
        // berarti pada piutang, dan membiarkannya terisi pada penjualan
        // tunai membuat laporan piutang memuat nota yang sudah dibayar.
        DB::statement('ALTER TABLE '.self::S.".sales ADD CONSTRAINT sales_cash_check
            CHECK (
                payment_type <> 'tunai'
                OR (payment_status = 'lunas' AND due_on IS NULL AND down_payment = 0)
            )");

        DB::statement('ALTER TABLE '.self::S.'.sales ADD CONSTRAINT sales_amount_check
            CHECK (subtotal >= 0 AND discount >= 0 AND total >= 0 AND down_payment >= 0)');

        Schema::create(self::S.'.sale_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('sale_id')->constrained(self::S.'.sales')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);

            /*
             * HARGA POKOK DIBEKUKAN. Kalau dibaca dari barangnya saat laporan
             * dibuat, keuntungan bulan lalu akan berubah setiap kali ada
             * penerimaan baru dengan harga berbeda — dan laporan keuangan
             * yang angkanya berubah sendiri tidak bisa dipakai menutup buku.
             */
            $table->decimal('unit_cost', 14, 2);

            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('subtotal', 16, 2);
            $table->timestampsTz();

            $table->unique(['sale_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.sale_items
            ADD CONSTRAINT sale_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.'.sale_items
            ADD CONSTRAINT sale_items_quantity_check CHECK (quantity > 0)');

        // ------------------------------------------------ retur jual

        Schema::create(self::S.'.sale_returns', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('return_number', 24)->unique();
            $table->unsignedBigInteger('sale_id');
            $table->timestampTz('returned_at');

            // Alasan wajib — sama seperti retur ke suplier.
            $table->text('reason');

            $table->decimal('total_amount', 16, 2)->default(0);

            /*
             * Retur atas penjualan PIUTANG mengurangi tagihan, bukan
             * mengeluarkan kas: menyamakannya dengan retur tunai membuat kas
             * tercatat keluar untuk uang yang belum pernah masuk.
             */
            $table->boolean('refunded_in_cash')->default(true);

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->index('sale_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.sale_returns
            ADD CONSTRAINT sale_returns_sale_fk
            FOREIGN KEY (sale_id) REFERENCES '.self::S.'.sales (id)');

        DB::statement('ALTER TABLE '.self::S.".sale_returns ADD CONSTRAINT sale_returns_reason_check
            CHECK (btrim(reason) <> '')");

        Schema::create(self::S.'.sale_return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('return_id')->constrained(self::S.'.sale_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->timestampsTz();

            $table->unique(['return_id', 'product_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.sale_return_items
            ADD CONSTRAINT sale_return_items_product_fk
            FOREIGN KEY (product_id) REFERENCES '.self::S.'.products (id)');

        DB::statement('ALTER TABLE '.self::S.'.sale_return_items
            ADD CONSTRAINT sale_return_items_quantity_check CHECK (quantity > 0)');

        // -------------------------------------------- bayar piutang

        Schema::create(self::S.'.sale_payments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('payment_number', 24)->unique();
            $table->unsignedBigInteger('sale_id');
            $table->timestampTz('paid_at');
            $table->decimal('amount', 16, 2);
            $table->string('method', 30)->default('tunai');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->index('sale_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.sale_payments
            ADD CONSTRAINT sale_payments_sale_fk
            FOREIGN KEY (sale_id) REFERENCES '.self::S.'.sales (id)');

        DB::statement('ALTER TABLE '.self::S.'.sale_payments
            ADD CONSTRAINT sale_payments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.sale_payments');
        Schema::dropIfExists(self::S.'.sale_return_items');
        Schema::dropIfExists(self::S.'.sale_returns');
        Schema::dropIfExists(self::S.'.sale_items');
        Schema::dropIfExists(self::S.'.sales');
        Schema::dropIfExists(self::S.'.members');
    }
};
