<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal pembayaran bank (domain K item G) — 7 kode.
 *
 * Menaungi pembayaran_bank_jabar, pembayaran_bank_jateng,
 * pembayaran_bank_mandiri, pembayaran_bank_papua, pembayaran_briva,
 * pembayaran_pihak_ke3_bankmandiri, dan set_tarif_online.
 *
 * SATU MEKANISME KANAL, BUKAN LIMA LAYAR PER BANK. Khanza memberi satu
 * dialog "Lihat Pembayaran" untuk tiap bank (DlgLhtBankJabar,
 * DlgLhtBankJateng, DlgLhtBankPapua, DlgLhtBankMandiri, DlgLhtBRIVA)
 * karena format berkas tiap bank berbeda. Tapi yang berbeda cuma cara
 * data itu MASUK; begitu sudah masuk, semuanya adalah hal yang sama —
 * sejumlah uang tiba di rekening rumah sakit dengan nomor rujukan, dan
 * harus dicocokkan ke tagihan. Lima tabel berarti lima layar pencocokan,
 * lima aturan pencocokan, dan bank keenam menuntut migrasi baru. Persis
 * jenis kesalahan yang sudah terlihat di skema Khanza sendiri lewat
 * penyakit_pd3i yang disusul perawatan_corona.
 *
 * KANALNYA JADI BARIS MASTER, bukan kolom bertipe enum: menambah bank
 * baru cukup menambah satu baris, tanpa migrasi.
 *
 * TABEL INI TIDAK MENYIMPAN UANG. Pembayaran yang sudah dicocokkan
 * dicatat sebagai billing.payments lewat InvoiceService::pay() seperti
 * pembayaran di kasir — inbox ini cuma menyimpan PEMBERITAHUANNYA dan
 * menunjuk pembayaran yang lahir darinya. Menyimpan nilainya di dua
 * tempat akan membuat dua angka yang bisa berbeda, kesalahan yang sudah
 * dihindari di item D dan item F.
 *
 * Yang belum ada dan dinyatakan terus terang: PENGHUBUNG KE BANK-nya
 * sendiri. Kanal, pencocokan, dan pencatatannya lengkap, tapi berkas atau
 * API tiap bank harus dibangun per bank saat kerja samanya benar-benar
 * ada — lengkap dengan kredensialnya. Sampai itu terjadi, pemberitahuan
 * masuk lewat entri manual oleh kasir, dan itu tetap berguna: rekening
 * koran bank memang dicocokkan manual di banyak rumah sakit.
 */
return new class extends Migration
{
    private const S = 'billing';

    private const JENIS = ['virtual-account', 'transfer', 'qris', 'edc', 'pihak-ketiga'];

    private const STATUS = ['diterima', 'tercocok', 'ditolak'];

    public function up(): void
    {
        Schema::create(self::S . '.payment_channels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique()->comment('Dipakai sebagai method pada billing.payments');
            $table->string('name', 120);
            $table->string('kind', 20)->comment('virtual-account/transfer/qris/edc/pihak-ketiga');
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->boolean('is_active')->default(true);

            // set_tarif_online: kanal ini boleh dipakai membayar tagihan
            // rawat jalan saja, rawat inap saja, atau keduanya.
            $table->boolean('allows_ralan')->default(true);
            $table->boolean('allows_ranap')->default(true);

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ' . self::S . ".payment_channels
            ADD CONSTRAINT payment_channels_kind_check
            CHECK (kind IN ('" . implode("','", self::JENIS) . "'))");

        Schema::create(self::S . '.channel_payments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('channel_id')->constrained(self::S . '.payment_channels');

            $table->string('reference_number', 80)->comment('Nomor rujukan dari bank');
            $table->string('virtual_account', 60)->nullable();
            $table->string('payer_name', 150)->nullable()->comment('Nama penyetor menurut bank');

            $table->decimal('amount', 15, 2);
            $table->timestampTz('paid_at');

            $table->string('status', 20)->default('diterima');

            // Tagihan yang dicocokkan, dan pembayaran yang lahir darinya.
            // Keduanya referensi; nilainya TIDAK disalin ke sini.
            $table->foreignId('invoice_id')->nullable()->constrained(self::S . '.invoices');
            $table->foreignId('payment_id')->nullable()->constrained(self::S . '.payments');

            $table->timestampTz('matched_at')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->string('rejection_reason', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'paid_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".channel_payments
            ADD CONSTRAINT channel_payments_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . '.channel_payments
            ADD CONSTRAINT channel_payments_amount_positive CHECK (amount > 0)');

        // Satu nomor rujukan bank hanya boleh masuk sekali per kanal.
        // Berkas rekening koran lazim diunggah berulang; tanpa ini,
        // pembayaran yang sama tercatat dua kali dan tagihan pasien
        // terlihat lunas berlebih.
        DB::statement('CREATE UNIQUE INDEX channel_payments_rujukan_unique
            ON ' . self::S . ".channel_payments (channel_id, reference_number)
            WHERE status <> 'ditolak'");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.channel_payments');
        Schema::dropIfExists(self::S . '.payment_channels');
    }
};
