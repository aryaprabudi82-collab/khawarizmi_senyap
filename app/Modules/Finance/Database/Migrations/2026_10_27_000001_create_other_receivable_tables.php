<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Piutang non-pasien (domain K item C) — ~15 kode.
 *
 * Menaungi piutang_jasa_perusahaan, kategori_piutang_jasa_perusahaan,
 * bayar_piutang_jasa_perusahaan, piutang_jasa_perusahaan_belum_lunas,
 * peminjam_piutang, bayar_piutang_lain,
 * piutang_peminjaman_uang_belum_lunas, piutang_obat_belum_lunas,
 * akun_piutang, dan akun_penagihan_piutang.
 *
 * TIGA HAL YANG ARAHNYA BERBEDA, meski Khanza menaruhnya satu domain:
 *
 *   1. Piutang jasa perusahaan  — perusahaan berhutang kepada RS
 *   2. Piutang peminjaman uang  — orang berhutang kepada RS
 *   3. Beban hutang lain        — RS berhutang kepada orang
 *
 * Dua yang pertama masuk tabel ini, dibedakan kolom kind: keduanya uang
 * yang akan MASUK, dengan bentuk penagihan dan pelunasan yang sama persis.
 * Yang ketiga TIDAK di sini — itu uang yang akan KELUAR, dan sudah punya
 * mekanismenya di finance.payables sejak item B; menaruhnya bersama
 * piutang hanya karena Khanza menaruhnya satu menu akan membuat setiap
 * laporan harus menyaring arah lebih dulu, dan cepat atau lambat ada yang
 * lupa lalu menjumlahkan hutang bersama piutang.
 *
 * Piutang penjamin (finance.receivables) dan piutang pasien
 * (billing.patient_receivables) juga TIDAK dilebur ke sini: keduanya lahir
 * dari tagihan pelayanan dan sudah punya alurnya sendiri sejak domain I.
 * Yang di sini justru yang TIDAK berasal dari pelayanan pasien.
 *
 * Sisa piutang SENGAJA TIDAK DISIMPAN, sama seperti hutang vendor dan
 * piutang pasien — selalu dihitung dari nilai dikurangi pembayarannya.
 */
return new class extends Migration
{
    private const S = 'finance';

    private const JENIS = ['jasa-perusahaan', 'peminjaman-uang'];

    private const STATUS = ['berjalan', 'lunas', 'dihapuskan'];

    public function up(): void
    {
        Schema::create(self::S . '.receivable_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('kind', 20)->comment('jasa-perusahaan / peminjaman-uang');
            $table->unsignedBigInteger('account_id')->nullable()
                ->comment('Akun piutang pada bagan akun (akun_piutang); null = belum dipetakan');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ' . self::S . ".receivable_categories
            ADD CONSTRAINT receivable_categories_kind_check
            CHECK (kind IN ('" . implode("','", self::JENIS) . "'))");

        Schema::create(self::S . '.other_receivables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('receivable_number', 30)->unique();

            $table->foreignId('category_id')->constrained(self::S . '.receivable_categories');
            $table->string('kind', 20)->comment('Disalin dari kategori supaya laporan satu query');

            // Siapa yang berhutang. Bisa perusahaan, bisa orang — disimpan
            // sebagai teks karena keduanya belum tentu terdaftar di master
            // mana pun, dan memaksakan master lebih dulu akan membuat
            // piutang tidak bisa dicatat saat kejadiannya.
            $table->string('debtor_name', 150);
            $table->string('debtor_contact', 120)->nullable();
            $table->unsignedBigInteger('debtor_ref_id')->nullable()
                ->comment('ID pegawai/perusahaan bila memang terdaftar; referensi longgar');

            $table->string('reference_number', 60)->nullable()->comment('No. perjanjian/kontrak/faktur');
            $table->date('issued_on');
            $table->date('due_date');

            $table->decimal('amount', 15, 2)->comment('Pokok piutang; sisa TIDAK disimpan, selalu dihitung');
            $table->string('description', 200);

            $table->string('status', 20)->default('berjalan');

            // Penghapusan piutang tak tertagih. Barisnya tidak dihapus:
            // piutang yang pernah diakui harus tetap terlihat berikut
            // alasan penghapusannya, karena penghapusan piutang adalah
            // keputusan yang harus bisa ditelusuri.
            $table->timestampTz('written_off_at')->nullable();
            $table->string('write_off_reason', 200)->nullable();
            $table->unsignedBigInteger('written_off_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['kind', 'status']);
            $table->index(['due_date', 'status']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".other_receivables
            ADD CONSTRAINT other_receivables_kind_check
            CHECK (kind IN ('" . implode("','", self::JENIS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".other_receivables
            ADD CONSTRAINT other_receivables_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . '.other_receivables
            ADD CONSTRAINT other_receivables_amount_positive CHECK (amount > 0)');

        Schema::create(self::S . '.other_receivable_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payment_number', 30)->unique();

            $table->foreignId('receivable_id')->constrained(self::S . '.other_receivables');

            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30)->default('transfer');
            $table->string('reference_number', 60)->nullable();
            $table->string('note', 200)->nullable();

            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 120)->nullable();
            $table->timestampsTz();

            $table->index('paid_on');
        });

        DB::statement('ALTER TABLE ' . self::S . '.other_receivable_payments
            ADD CONSTRAINT other_receivable_payments_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.other_receivable_payments');
        Schema::dropIfExists(self::S . '.other_receivables');
        Schema::dropIfExists(self::S . '.receivable_categories');
    }
};
