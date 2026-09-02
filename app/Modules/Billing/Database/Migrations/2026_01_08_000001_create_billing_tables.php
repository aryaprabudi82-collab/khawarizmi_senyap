<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks billing: tagihan dan pembayaran rawat jalan.
 *
 * Dua hal yang menentukan bentuk tabel di sini:
 *
 *  1. Charge line ditarik dari konteks lain, bukan ditulis manual. Biaya
 *     registrasi (encounter), obat yang diserahkan (pharmacy) — nanti order
 *     dan tindakan menyusul — masuk ke charge_lines lewat proses sinkronisasi
 *     yang idempoten: dijalankan berkali-kali tidak pernah menggandakan baris,
 *     karena kuncinya adalah peristiwa sumbernya (source_type, source_id),
 *     bukan kapan proses sinkronisasi terakhir jalan.
 *
 *  2. Pelunasan hanya untuk penjamin 'umum'. BPJS, asuransi, dan perusahaan
 *     tidak membayar di kasir rawat jalan — tagihannya jadi piutang yang
 *     ditagihkan lewat klaim, alur yang menyusul di domain finance/integration.
 *     Kasir di sini hanya menagih penjamin 'umum'.
 *
 * Volume pada 2.000 pasien/hari: ~10 baris tagihan per kunjungan (registrasi +
 * beberapa obat + nanti tindakan/penunjang), sehingga charge_lines diproyeksi
 * tumbuh ~6 juta baris per tahun — dipartisi bulanan sejak awal, seperti
 * audit_logs, observations, dan stock_movements.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . self::S);

        $this->createInvoices();
        $this->createChargeLines();
        $this->createPayments();
        $this->createSequences();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::S . '.charge_lines CASCADE');

        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.payments');
        Schema::dropIfExists(self::S . '.invoices');
    }

    private function createInvoices(): void
    {
        Schema::create(self::S . '.invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('invoice_number', 24)->unique();

            // Rujukan lintas konteks: id disimpan, foreign key tidak dibuat.
            $table->unsignedBigInteger('registration_id')->unique();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('payer_id');

            // Salinan untuk layar kasir, supaya tidak perlu menyeberang konteks
            // hanya untuk menampilkan nama pasien di daftar tagihan.
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);
            $table->string('unit_name', 150)->nullable();
            $table->string('payer_name', 150);
            $table->string('payer_kind', 20)->comment('umum, bpjs, asuransi, perusahaan');

            /*
             * pasien   : ditagihkan dan dilunasi langsung di kasir
             * penjamin : menjadi piutang, dilunasi lewat klaim (domain finance)
             */
            $table->string('payment_responsibility', 20);

            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->string('status', 20)->default('terbuka')
                ->comment('terbuka, lunas, ditanggung-penjamin, void');

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            $table->text('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'opened_at']);
            $table->index('patient_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".invoices ADD CONSTRAINT invoices_status_check
            CHECK (status IN ('terbuka','lunas','ditanggung-penjamin','void'))");
        DB::statement("ALTER TABLE " . self::S . ".invoices ADD CONSTRAINT invoices_responsibility_check
            CHECK (payment_responsibility IN ('pasien','penjamin'))");
        DB::statement('ALTER TABLE ' . self::S . '.invoices
            ADD CONSTRAINT invoices_paid_not_exceeding CHECK (paid_amount <= total_amount)');
        DB::statement('ALTER TABLE ' . self::S . '.invoices
            ADD CONSTRAINT invoices_amounts_non_negative CHECK (total_amount >= 0 AND paid_amount >= 0)');
    }

    /**
     * Baris tagihan, ditarik dari konteks lain lewat sinkronisasi idempoten.
     *
     * charged_at diambil dari waktu peristiwa sumbernya (registered_at,
     * dispensed_at, dst.), bukan waktu sinkronisasi dijalankan — nilai itu
     * tidak pernah berubah setelah tercatat, sehingga aman dijadikan bagian
     * kunci unik di (charged_at, source_type, source_id) walau tabelnya
     * dipartisi berdasarkan charged_at.
     */
    private function createChargeLines(): void
    {
        DB::statement('
            CREATE TABLE ' . self::S . '.charge_lines (
                id             bigint GENERATED ALWAYS AS IDENTITY,
                charged_at     timestamptz  NOT NULL,
                invoice_id     bigint       NOT NULL,
                registration_id bigint      NOT NULL,
                source_type    varchar(20)  NOT NULL,
                source_id      bigint       NOT NULL,
                description    varchar(200) NOT NULL,
                quantity       numeric(12,2) NOT NULL DEFAULT 1,
                unit_price     numeric(14,2) NOT NULL,
                amount         numeric(14,2) NOT NULL,
                created_at     timestamptz  NOT NULL DEFAULT now(),
                PRIMARY KEY (id, charged_at)
            ) PARTITION BY RANGE (charged_at)
        ');

        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = 0; $i < 24; $i++) {
            $from = $start->modify("+{$i} months");
            $to = $from->modify('+1 month');

            DB::statement(sprintf(
                'CREATE TABLE %s.charge_lines_%s PARTITION OF %s.charge_lines FOR VALUES FROM (%s) TO (%s)',
                self::S,
                $from->format('Y_m'),
                self::S,
                "'" . $from->format('Y-m-d') . "'",
                "'" . $to->format('Y-m-d') . "'"
            ));
        }

        DB::statement('CREATE TABLE ' . self::S . '.charge_lines_default PARTITION OF '
            . self::S . '.charge_lines DEFAULT');

        DB::statement("ALTER TABLE " . self::S . ".charge_lines ADD CONSTRAINT charge_lines_source_check
            CHECK (source_type IN ('registrasi','resep_obat'))");

        // Kunci idempotensi sinkronisasi: peristiwa sumber yang sama tidak
        // pernah ditarik dua kali, walau proses sinkronisasi dijalankan
        // berkali-kali atau lebih dari satu proses berjalan bersamaan.
        DB::statement('CREATE UNIQUE INDEX charge_lines_source_unique
            ON ' . self::S . '.charge_lines (charged_at, source_type, source_id)');

        DB::statement('CREATE INDEX charge_lines_invoice_idx
            ON ' . self::S . '.charge_lines (invoice_id)');
        DB::statement('CREATE INDEX charge_lines_registration_idx
            ON ' . self::S . '.charge_lines (registration_id)');
    }

    /**
     * Pembayaran. Hanya menerima INSERT — koreksi kesalahan input dilakukan
     * lewat pembatalan (voided_at), bukan mengubah atau menghapus baris,
     * supaya jejak transaksi keuangan tetap utuh.
     */
    private function createPayments(): void
    {
        Schema::create(self::S . '.payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payment_number', 24)->unique();
            $table->foreignId('invoice_id')->constrained(self::S . '.invoices')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('method', 20)->default('tunai')->comment('tunai, debit, kredit, qris, transfer');

            $table->timestampTz('paid_at');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->string('note', 255)->nullable();

            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();

            $table->timestampsTz();

            $table->index(['invoice_id', 'paid_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".payments ADD CONSTRAINT payments_method_check
            CHECK (method IN ('tunai','debit','kredit','qris','transfer'))");
        DB::statement('ALTER TABLE ' . self::S . '.payments
            ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
    }

    private function createSequences(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }
};
