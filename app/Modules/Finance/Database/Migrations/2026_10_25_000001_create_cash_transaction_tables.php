<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kas harian: pemasukan dan pengeluaran di luar pelayanan (domain K item A).
 *
 * Menaungi pemasukan_lain, kategori_pemasukan_lain, pengeluaran,
 * kategori_pengeluaran_harian, pengeluaran_pengeluaran, omset_penerimaan,
 * cashflow, dan keuangan.
 *
 * SATU TABEL, BUKAN DUA. Khanza memisahkan pemasukan dan pengeluaran jadi
 * menu sendiri-sendiri, tapi keduanya adalah hal yang sama dilihat dari
 * arah berbeda: uang yang masuk atau keluar dari kas, di luar tagihan
 * pasien. Memisahkannya menjadi dua tabel akan memaksa tiap laporan arus
 * kas menggabungkan keduanya lewat UNION, dan setiap penyaring baru harus
 * ditulis dua kali — persis jenis duplikasi yang membuat satu sisi
 * diam-diam ketinggalan penyaring dan angkanya jadi tidak cocok.
 *
 * Arahnya dibedakan lewat kolom direction, dan nilainya SELALU DISIMPAN
 * POSITIF. Menyimpan pengeluaran sebagai angka negatif tampak praktis
 * sampai seseorang menjumlahkan seluruh kolom dan mendapat "total kas"
 * yang sebenarnya selisih — kesalahan yang tidak terlihat pada laporan
 * yang isinya sedikit.
 *
 * Kategori sengaja jadi TABEL, bukan teks bebas, karena inilah yang
 * dipetakan ke bagan akun: kategori bebas ketik akan membuat pemetaan
 * akun mustahil dijaga, dan pos yang salah ketik hilang dari laporan
 * tanpa jejak.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        Schema::create(self::S . '.cash_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('direction', 10)->comment('masuk / keluar');

            // Pemetaan ke bagan akun. Longgar (tanpa FK) supaya kategori
            // tetap bisa dipakai sebelum bagan akunnya lengkap — pola yang
            // sama dipakai account_mappings sejak domain I item E.
            $table->unsignedBigInteger('account_id')->nullable()
                ->comment('Akun bagan yang menampung pos ini; null = belum dipetakan');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ' . self::S . ".cash_categories
            ADD CONSTRAINT cash_categories_direction_check
            CHECK (direction IN ('masuk','keluar'))");

        Schema::create(self::S . '.cash_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('transaction_number', 30)->unique();

            $table->foreignId('category_id')->constrained(self::S . '.cash_categories');
            $table->string('direction', 10)->comment('Disalin dari kategori supaya laporan arus kas satu query');

            $table->date('transaction_date');
            $table->decimal('amount', 15, 2)->comment('SELALU POSITIF; arahnya dibaca dari direction');
            $table->string('description', 200);
            $table->string('counterparty', 120)->nullable()->comment('Dari siapa / kepada siapa');
            $table->string('payment_method', 30)->default('tunai');
            $table->string('reference_number', 60)->nullable()->comment('No. kuitansi/bukti');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 120)->nullable();

            // Pembatalan tidak menghapus baris: kas yang pernah tercatat
            // harus tetap terlihat berikut alasan pembatalannya, sama
            // seperti pola void pada tagihan.
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 200)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();

            $table->timestampsTz();

            $table->index(['transaction_date', 'direction']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".cash_transactions
            ADD CONSTRAINT cash_transactions_direction_check
            CHECK (direction IN ('masuk','keluar'))");

        DB::statement('ALTER TABLE ' . self::S . '.cash_transactions
            ADD CONSTRAINT cash_transactions_amount_positive
            CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.cash_transactions');
        Schema::dropIfExists(self::S . '.cash_categories');
    }
};
