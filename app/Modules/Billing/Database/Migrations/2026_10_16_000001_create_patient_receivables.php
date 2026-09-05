<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain I item B: piutang pasien (piutang_pasien, piutang_ralan,
 * piutang_ranap).
 *
 * BUKAN duplikat finance.receivables. Dua konsep berbeda yang memang
 * hidup berdampingan:
 *
 *  - finance.receivables : PENJAMIN berutang ke rumah sakit. Tagihan
 *    BPJS/asuransi/perusahaan yang ditagih lewat klaim, ditutup dengan
 *    bukti transfer/SP2D. Sudah ada sejak Wave 1.
 *  - billing.patient_receivables (ini) : PASIEN berutang ke rumah sakit.
 *    Pasien pulang tanpa melunasi, membayar uang muka, sisanya jatuh
 *    tempo dan dicicil. Skema Khanza-nya (piutang_pasien) berkunci pada
 *    no_rawat dengan status Lunas/Belum Lunas, uangmuka, sisapiutang,
 *    dan tgltempo — jelas utang pasien, bukan klaim penjamin.
 *
 * YANG SENGAJA TIDAK DISIMPAN DI SINI: nominal sisa piutang. Khanza
 * menyimpannya (sisapiutang), tapi di sini uang sudah punya satu sumber
 * kebenaran — billing.invoices.paid_amount yang hanya berubah lewat
 * InvoiceService::pay() dengan UPDATE bersyarat. Menyimpan salinan sisa
 * di tabel kedua berarti dua angka yang bisa berselisih, dan yang
 * berselisih soal uang selalu jadi masalah. Uang muka pun dicatat sebagai
 * pembayaran biasa lewat pay(), bukan kolom tersendiri, supaya cicilan
 * dan uang muka menempuh jalur yang persis sama.
 *
 * Jadi baris di sini hanya menyimpan yang memang tidak diketahui tagihan:
 * kesepakatan jatuh temponya, dan fakta bahwa sisa tagihan ini resmi
 * dijadikan utang.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        Schema::create(self::S . '.patient_receivables', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Satu kunjungan satu piutang — sama seperti Khanza yang
            // menjadikan no_rawat sebagai primary key.
            $table->foreignId('invoice_id')->constrained(self::S . '.invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            $table->string('patient_name', 150);
            $table->string('care_type', 20)->comment('Disalin dari tagihan — menentukan gerbang piutang_ralan vs piutang_ranap');

            $table->decimal('principal_amount', 14, 2)
                ->comment('Sisa tagihan saat piutang dibuat, dibekukan sebagai catatan besaran utang yang disepakati');
            $table->date('due_date');

            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->comment('ID pengguna platform, referensi longgar');

            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestampsTz();

            $table->index(['care_type', 'due_date']);
            $table->index('patient_id');
        });

        DB::statement('ALTER TABLE ' . self::S . '.patient_receivables ADD CONSTRAINT patient_receivables_principal_check
            CHECK (principal_amount > 0)');

        /*
         * Unique PARSIAL, bukan unique biasa: satu tagihan hanya boleh punya
         * satu piutang yang masih BERLAKU. Piutang yang salah dibuat harus
         * bisa dibatalkan lalu diganti yang benar — unique tanpa syarat akan
         * membuat baris yang sudah dibatalkan ikut memblokir selamanya.
         * Pola sama dengan indeks sesi parkir yang masih terbuka.
         */
        DB::statement('CREATE UNIQUE INDEX patient_receivables_tagihan_berlaku_unique ON ' . self::S . '.patient_receivables (invoice_id)
            WHERE cancelled_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.patient_receivables');
    }
};
