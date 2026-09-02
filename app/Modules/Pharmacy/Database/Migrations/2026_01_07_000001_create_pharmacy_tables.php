<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks pharmacy: resep, telaah, penyerahan, dan stok.
 *
 * Tiga hal yang menentukan bentuk tabel di sini:
 *
 *  1. Stok adalah state yang paling tidak boleh terbelah. Jumlah tersedia
 *     hanya ada di satu tempat (stock_batches.quantity_on_hand) dan setiap
 *     perubahannya meninggalkan baris di buku besar stock_movements. Saldo
 *     selalu bisa direkonstruksi dari buku besarnya.
 *
 *  2. Narkotika dan psikotropika wajib bisa dilacak per batch dan dilaporkan.
 *     Karena itu penyerahan selalu menunjuk batch, bukan sekadar obatnya.
 *
 *  3. Telaah resep oleh apoteker adalah syarat, bukan pelengkap. Resep tidak
 *     bisa lompat dari ditulis langsung ke diserahkan.
 *
 * Volume pada 2.000 pasien/hari: ~70% kunjungan menghasilkan resep dengan
 * rata-rata tiga item, sehingga prescription_items tumbuh ~1,26 juta baris
 * per tahun dan stock_movements sekitar angka yang sama. Buku besar stok
 * dipartisi bulanan sejak awal.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . self::S);

        $this->createDrugs();
        $this->createStockLocations();
        $this->createStockBatches();
        $this->createStockMovements();
        $this->createPrescriptions();
        $this->createPrescriptionItems();
        $this->createPrescriptionReviews();
        $this->createSequences();
        $this->createPublishedViews();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_prescription_charge');
        DB::statement('DROP TABLE IF EXISTS ' . self::S . '.stock_movements CASCADE');

        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.prescription_reviews');
        Schema::dropIfExists(self::S . '.prescription_items');
        Schema::dropIfExists(self::S . '.prescriptions');
        Schema::dropIfExists(self::S . '.stock_batches');
        Schema::dropIfExists(self::S . '.stock_locations');
        Schema::dropIfExists(self::S . '.drugs');
    }

    private function createDrugs(): void
    {
        Schema::create(self::S . '.drugs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique();

            $table->string('name', 200)->comment('Nama dagang atau nama sediaan');
            $table->string('generic_name', 200)->nullable()->comment('Nama generik, dasar penyaringan alergi');

            // Kamus Farmasi dan Alat Kesehatan Kemenkes. Terminologi milik pihak
            // luar, dipakai apa adanya saat resep dikirim ke SATUSEHAT.
            $table->string('kfa_code', 30)->nullable()->index();

            $table->string('category', 20)->default('obat')->comment('obat, bhp, alkes');
            $table->string('form', 40)->nullable()->comment('tablet, kapsul, sirup, injeksi, salep');
            $table->string('strength', 40)->nullable()->comment('mis. 500 mg');
            $table->string('unit', 20)->comment('Satuan terkecil penyerahan');

            $table->boolean('requires_prescription')->default(true);
            $table->boolean('is_narcotic')->default(false);
            $table->boolean('is_psychotropic')->default(false);
            $table->boolean('is_high_alert')->default(false)->comment('Obat dengan risiko tinggi bila salah');

            $table->decimal('sell_price', 14, 2)->default(0);
            $table->decimal('minimum_stock', 12, 2)->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->index(['category', 'is_active']);
        });

        DB::statement("ALTER TABLE " . self::S . ".drugs ADD CONSTRAINT drugs_category_check
            CHECK (category IN ('obat','bhp','alkes'))");

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX drugs_name_trgm_idx
            ON ' . self::S . '.drugs USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX drugs_generic_trgm_idx
            ON ' . self::S . '.drugs USING gin (generic_name gin_trgm_ops)');
    }

    private function createStockLocations(): void
    {
        Schema::create(self::S . '.stock_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('kind', 20)->default('depo')->comment('gudang, depo');

            // Depo biasanya melekat pada satu unit layanan. Id-nya saja yang
            // disimpan: organization berada di schema lain.
            $table->unsignedBigInteger('unit_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /**
     * Saldo stok per batch.
     *
     * quantity_on_hand adalah satu-satunya tempat jumlah tersedia disimpan.
     * Setiap perubahannya wajib lewat pernyataan UPDATE bersyarat yang
     * mencegah stok jatuh di bawah nol.
     */
    private function createStockBatches(): void
    {
        Schema::create(self::S . '.stock_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('drug_id')->constrained(self::S . '.drugs')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained(self::S . '.stock_locations')->cascadeOnDelete();

            $table->string('batch_number', 40);
            $table->date('expiry_date')->nullable();

            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('cost_price', 14, 2)->default(0);

            $table->timestampsTz();

            // Penyerahan memakai kaidah FEFO: yang paling dekat kedaluwarsa
            // keluar lebih dulu.
            $table->index(['drug_id', 'location_id', 'expiry_date'], 'stock_fefo_idx');
        });

        DB::statement('ALTER TABLE ' . self::S . '.stock_batches
            ADD CONSTRAINT stock_batches_non_negative CHECK (quantity_on_hand >= 0)');

        DB::statement('CREATE UNIQUE INDEX stock_batches_unique
            ON ' . self::S . '.stock_batches (drug_id, location_id, batch_number)');
    }

    /**
     * Buku besar pergerakan stok.
     *
     * Hanya menerima INSERT. Saldo di stock_batches boleh dianggap cache yang
     * dipercepat; kebenarannya selalu bisa diuji ulang terhadap tabel ini —
     * dan untuk narkotika serta psikotropika, inilah dasar pelaporannya.
     */
    private function createStockMovements(): void
    {
        DB::statement('
            CREATE TABLE ' . self::S . '.stock_movements (
                id             bigint GENERATED ALWAYS AS IDENTITY,
                moved_at       timestamptz  NOT NULL DEFAULT now(),
                batch_id       bigint       NOT NULL,
                drug_id        bigint       NOT NULL,
                location_id    bigint       NOT NULL,
                kind           varchar(20)  NOT NULL,
                quantity       numeric(12,2) NOT NULL,
                balance_after  numeric(12,2) NOT NULL,
                reference_type varchar(40)  NULL,
                reference_id   bigint       NULL,
                note           varchar(255) NULL,
                created_by     bigint       NULL,
                PRIMARY KEY (id, moved_at)
            ) PARTITION BY RANGE (moved_at)
        ');

        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = 0; $i < 24; $i++) {
            $from = $start->modify("+{$i} months");
            $to = $from->modify('+1 month');

            DB::statement(sprintf(
                'CREATE TABLE %s.stock_movements_%s PARTITION OF %s.stock_movements FOR VALUES FROM (%s) TO (%s)',
                self::S,
                $from->format('Y_m'),
                self::S,
                "'" . $from->format('Y-m-d') . "'",
                "'" . $to->format('Y-m-d') . "'"
            ));
        }

        DB::statement('CREATE TABLE ' . self::S . '.stock_movements_default PARTITION OF '
            . self::S . '.stock_movements DEFAULT');

        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','koreksi','kadaluarsa','rusak'))");

        DB::statement('CREATE INDEX stock_movements_batch_idx
            ON ' . self::S . '.stock_movements (batch_id, moved_at DESC)');
        DB::statement('CREATE INDEX stock_movements_drug_idx
            ON ' . self::S . '.stock_movements (drug_id, moved_at DESC)');
        DB::statement('CREATE INDEX stock_movements_reference_idx
            ON ' . self::S . '.stock_movements (reference_type, reference_id)');
    }

    private function createPrescriptions(): void
    {
        Schema::create(self::S . '.prescriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('prescription_number', 24)->unique();

            // Rujukan lintas konteks: id disimpan, foreign key tidak dibuat.
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);
            $table->string('unit_name', 150)->nullable();

            $table->unsignedBigInteger('prescriber_id')->nullable();
            $table->string('prescriber_name', 150)->nullable();

            /*
             * Alurnya tidak boleh dipotong: resep yang ditulis wajib melewati
             * telaah apoteker sebelum bisa disiapkan dan diserahkan.
             */
            $table->string('status', 24)->default('ditulis')
                ->comment('ditulis, menunggu-telaah, disetujui, ditolak, diserahkan, batal');

            $table->timestampTz('prescribed_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('dispensed_at')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name', 150)->nullable();
            $table->unsignedBigInteger('dispensed_by')->nullable();
            $table->string('dispensed_by_name', 150)->nullable();

            $table->foreignId('location_id')->nullable()->constrained(self::S . '.stock_locations')->nullOnDelete();

            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('cancellation_reason')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'prescribed_at']);
            $table->index(['registration_id']);
            $table->index(['patient_id', 'prescribed_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".prescriptions ADD CONSTRAINT prescriptions_status_check
            CHECK (status IN ('ditulis','menunggu-telaah','disetujui','ditolak','diserahkan','batal'))");

        // Satu kunjungan boleh punya beberapa resep, tapi tidak boleh punya dua
        // resep yang masih berjalan sekaligus — itu tanda tombol simpan ganda.
        DB::statement("CREATE UNIQUE INDEX prescriptions_single_open
            ON " . self::S . ".prescriptions (registration_id)
            WHERE status IN ('ditulis','menunggu-telaah') AND deleted_at IS NULL");
    }

    private function createPrescriptionItems(): void
    {
        Schema::create(self::S . '.prescription_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('prescription_id')->constrained(self::S . '.prescriptions')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs');

            // Disalin saat penulisan: harga dan nama obat boleh berubah, isi
            // resep yang sudah ditulis tidak boleh ikut berubah.
            $table->string('drug_name', 200);
            $table->string('drug_unit', 20);
            $table->decimal('unit_price', 14, 2)->default(0);

            $table->decimal('quantity', 12, 2);
            $table->string('dosage_instruction', 200)->comment('Aturan pakai, mis. 3x1 tablet sesudah makan');
            $table->string('note', 255)->nullable();

            $table->decimal('dispensed_quantity', 12, 2)->default(0);
            $table->foreignId('substituted_from_drug_id')->nullable()->constrained(self::S . '.drugs');

            $table->timestampsTz();

            $table->index('drug_id');
        });

        DB::statement('ALTER TABLE ' . self::S . '.prescription_items
            ADD CONSTRAINT prescription_items_quantity_positive CHECK (quantity > 0)');
    }

    /**
     * Telaah resep oleh apoteker.
     *
     * Temuannya disimpan, bukan cuma ditampilkan sekali lalu hilang. Penolakan
     * maupun persetujuan sama-sama meninggalkan jejak.
     */
    private function createPrescriptionReviews(): void
    {
        Schema::create(self::S . '.prescription_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('prescription_id')->constrained(self::S . '.prescriptions')->cascadeOnDelete();

            $table->string('outcome', 20)->comment('disetujui, ditolak');

            /*
             * Temuan penyaringan otomatis, disimpan sebagaimana adanya saat
             * telaah dilakukan. Data alergi pasien bisa berubah kemudian;
             * dasar keputusan apoteker tidak boleh ikut berubah.
             */
            $table->jsonb('findings')->nullable();

            $table->text('pharmacist_note')->nullable();

            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('reviewer_name', 150)->nullable();
            $table->timestampTz('reviewed_at');

            $table->index('prescription_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".prescription_reviews ADD CONSTRAINT reviews_outcome_check
            CHECK (outcome IN ('disetujui','ditolak'))");
    }

    private function createSequences(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    private function createPublishedViews(): void
    {
        // billing memakai ini untuk menarik biaya obat ke tagihan kunjungan.
        DB::statement("CREATE VIEW " . self::S . ".v_prescription_charge AS
            SELECT i.id             AS item_id,
                   p.id             AS prescription_id,
                   p.registration_id,
                   p.patient_id,
                   p.prescription_number,
                   p.dispensed_at,
                   i.drug_id,
                   i.drug_name,
                   i.dispensed_quantity,
                   i.unit_price,
                   -- Dibulatkan eksplisit: perkalian numeric di PostgreSQL
                   -- menghasilkan skala 4, dan bentuk kontrak publik harus pasti.
                   ROUND(i.dispensed_quantity * i.unit_price, 2)::numeric(14,2) AS amount
            FROM " . self::S . ".prescriptions p
            JOIN " . self::S . ".prescription_items i ON i.prescription_id = p.id
            WHERE p.status = 'diserahkan' AND p.deleted_at IS NULL");
    }
};
