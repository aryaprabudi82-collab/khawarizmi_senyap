<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hutang vendor / hutang usaha (domain K item B) — ~20 kode.
 *
 * Menaungi titip_faktur_* (4), validasi_tagihan_* (4), hutang_* (4),
 * ringkasan_hutang_vendor_* (4), bayar_pemesanan_* dan bayar_pesan_* (4),
 * tagihan_hutang_obat, dan akun_bayar_hutang.
 *
 * SATU BUKU HUTANG UNTUK EMPAT RANTAI PENGADAAN. Khanza memberi menu
 * sendiri-sendiri untuk obat, non-medis, dapur, dan aset — tapi hutang
 * kepada vendor adalah hutang yang sama, dan yang membedakan cuma dari
 * rantai mana barangnya datang. Empat tabel terpisah berarti setiap
 * laporan hutang harus di-UNION, setiap aturan pelunasan ditulis empat
 * kali, dan umur hutang dihitung empat kali dengan risiko salah satunya
 * tertinggal saat aturannya berubah. Rantai asalnya cukup jadi kolom.
 *
 * NILAI HUTANG DIBEKUKAN SAAT FAKTUR DIVALIDASI, mengikuti prinsip yang
 * sama seperti tarif tindakan dan piutang pasien: yang disepakati saat
 * itulah yang terutang. Kalau nilainya dihitung ulang dari penerimaan
 * barang setiap kali laporan dibuka, koreksi harga di rantai pengadaan
 * akan diam-diam mengubah hutang yang sudah divalidasi — dan neraca bulan
 * lalu ikut berubah tanpa ada yang menyentuhnya.
 *
 * SISA HUTANG SENGAJA TIDAK DISIMPAN, sama seperti piutang pasien di
 * domain I item B. Sisa yang disimpan akan menyimpang dari jumlah
 * pembayarannya begitu ada satu pembayaran yang gagal memperbaruinya, dan
 * penyimpangan itu tidak akan terlihat sampai seseorang menjumlahkan
 * ulang. Sisa selalu dihitung: nilai dibekukan dikurangi jumlah bayar.
 */
return new class extends Migration
{
    private const S = 'finance';

    /** Rantai pengadaan asal barangnya. */
    private const SUMBER = ['farmasi', 'non-medis', 'dapur', 'aset'];

    private const STATUS = ['dititipkan', 'tervalidasi', 'ditolak', 'lunas'];

    public function up(): void
    {
        Schema::create(self::S . '.payables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payable_number', 30)->unique();

            $table->string('source_context', 20)->comment('farmasi/non-medis/dapur/aset — rantai asal barangnya');
            $table->unsignedBigInteger('goods_receipt_id')->nullable()
                ->comment('ID penerimaan pada konteks asalnya, referensi longgar lintas schema');
            $table->string('receipt_number', 30)->nullable();

            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('supplier_name', 150)->comment('Disalin supaya ringkasan per vendor satu query');

            $table->string('invoice_number', 60)->comment('Nomor faktur yang dititipkan vendor');
            $table->date('invoice_date');
            $table->date('due_date')->comment('Jatuh tempo — dasar hitungan umur hutang');

            $table->decimal('amount', 15, 2)->comment('DIBEKUKAN saat validasi; tidak dihitung ulang');

            $table->string('status', 20)->default('dititipkan');

            $table->timestampTz('validated_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->string('rejection_reason', 200)->nullable();

            $table->unsignedBigInteger('account_id')->nullable()
                ->comment('Akun hutang pada bagan akun (akun_bayar_hutang); null = belum dipetakan');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['supplier_name', 'status']);
            $table->index(['due_date', 'status']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".payables
            ADD CONSTRAINT payables_source_check
            CHECK (source_context IN ('" . implode("','", self::SUMBER) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".payables
            ADD CONSTRAINT payables_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . '.payables
            ADD CONSTRAINT payables_amount_positive CHECK (amount > 0)');

        // Satu faktur per vendor hanya boleh dititipkan sekali, KECUALI
        // yang ditolak — faktur yang ditolak boleh dititipkan ulang setelah
        // diperbaiki. Indeks parsial, pola yang sama seperti tagihan yang
        // di-void dan piutang pasien yang dibatalkan.
        DB::statement('CREATE UNIQUE INDEX payables_faktur_vendor_unique
            ON ' . self::S . ".payables (supplier_name, invoice_number)
            WHERE status <> 'ditolak'");

        Schema::create(self::S . '.payable_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payment_number', 30)->unique();

            $table->foreignId('payable_id')->constrained(self::S . '.payables');

            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30)->default('transfer');
            $table->string('reference_number', 60)->nullable();
            $table->string('note', 200)->nullable();

            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('paid_by_name', 120)->nullable();
            $table->timestampsTz();

            $table->index('paid_on');
        });

        DB::statement('ALTER TABLE ' . self::S . '.payable_payments
            ADD CONSTRAINT payable_payments_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.payable_payments');
        Schema::dropIfExists(self::S . '.payables');
    }
};
