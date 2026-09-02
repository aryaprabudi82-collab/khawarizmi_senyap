<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks finance: jurnal dan piutang penjamin.
 *
 * Menutup placeholder yang ditinggalkan billing: tagihan dengan
 * payment_responsibility='penjamin' sejauh ini hanya berhenti pada label
 * status 'ditanggung-penjamin'. Di sinilah label itu menjadi kewajiban yang
 * bisa diaudit — piutang bernilai uang dengan jurnal berpasangan.
 *
 * Dua hal yang menentukan bentuk tabel:
 *
 *  1. Jurnal berpasangan (double entry) ditegakkan di kode, bukan diharap
 *     dari disiplin pemanggil. PostingService::post() adalah SATU-SATUNYA
 *     jalan membuat entri jurnal, dan ia selalu menulis baris debit dan
 *     kredit senilai sama dalam satu transaksi. journal_lines tidak
 *     pernah diinsert langsung dari luar kelas itu.
 *
 *  2. Postingan ditarik dari billing lewat sinkronisasi idempoten, pola
 *     yang sama dengan charge_lines: kunci uniknya peristiwa sumbernya
 *     (reference_type, reference_id), bukan kapan sinkronisasi dijalankan.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . self::S);

        $this->createChartOfAccounts();
        $this->createJournalEntries();
        $this->createJournalLines();
        $this->createReceivables();
        $this->createSequences();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.receivables');
        Schema::dropIfExists(self::S . '.journal_lines');
        Schema::dropIfExists(self::S . '.journal_entries');
        Schema::dropIfExists(self::S . '.chart_of_accounts');
    }

    private function createChartOfAccounts(): void
    {
        Schema::create(self::S . '.chart_of_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 20)->comment('kas, piutang, pendapatan, beban');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".chart_of_accounts ADD CONSTRAINT accounts_type_check
            CHECK (type IN ('kas','piutang','pendapatan','beban'))");
    }

    /**
     * Header jurnal. Hanya menerima INSERT — tidak ada jalur UPDATE atau
     * DELETE; koreksi salah posting dilakukan lewat entri pembalik
     * (jurnal baru yang membalik baris lama), bukan menyunting yang sudah ada.
     */
    private function createJournalEntries(): void
    {
        Schema::create(self::S . '.journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entry_number', 24)->unique();
            $table->timestampTz('entry_date');
            $table->string('description', 255);

            // Peristiwa sumber di konteks lain: id disimpan, foreign key
            // tidak dibuat. reference_type + reference_id sekaligus jadi
            // kunci idempotensi sinkronisasi dari billing.
            $table->string('reference_type', 30)->comment('invoice, penerimaan-piutang');
            $table->unsignedBigInteger('reference_id');

            $table->unsignedBigInteger('posted_by')->nullable()->comment('Null berarti diposting otomatis oleh sistem');
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX journal_entries_source_unique
            ON ' . self::S . '.journal_entries (reference_type, reference_id)');
    }

    private function createJournalLines(): void
    {
        Schema::create(self::S . '.journal_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('journal_entry_id')->constrained(self::S . '.journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained(self::S . '.chart_of_accounts');

            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('description', 255)->nullable();

            $table->index('account_id');
        });

        // Satu baris hanya mengisi salah satu sisi, tidak pernah dua-duanya
        // dan tidak pernah nol-nol.
        DB::statement('ALTER TABLE ' . self::S . '.journal_lines
            ADD CONSTRAINT journal_lines_single_side CHECK (
                (debit > 0 AND credit = 0) OR (debit = 0 AND credit > 0)
            )');
    }

    /**
     * Piutang ke penjamin. Satu tagihan yang ditanggung penjamin menghasilkan
     * satu piutang. Penagihan sesungguhnya ke BPJS/asuransi (verifikasi
     * klaim, pembayaran batch mencakup ratusan SEP sekaligus) adalah proses
     * INACBG/bridging yang jauh lebih rumit dari cakupan Wave 1 ini —
     * collectReceivable() di sini mewakili penerimaan pembayaran secara
     * sederhana, satu piutang sekali tagih.
     */
    private function createReceivables(): void
    {
        Schema::create(self::S . '.receivables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('payer_id');

            $table->string('invoice_number', 24);
            $table->string('patient_name', 150);
            $table->string('payer_name', 150);
            $table->string('payer_kind', 20);

            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('terbuka')->comment('terbuka, tertagih');

            $table->timestampTz('opened_at');
            $table->timestampTz('collected_at')->nullable();
            $table->unsignedBigInteger('collected_by')->nullable();
            $table->string('collection_reference', 100)->nullable()->comment('No. bukti transfer / SP2D penjamin');

            $table->timestampsTz();

            $table->index(['status', 'payer_id']);
            $table->index(['patient_id']);
        });

        DB::statement("ALTER TABLE " . self::S . ".receivables ADD CONSTRAINT receivables_status_check
            CHECK (status IN ('terbuka','tertagih'))");
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
