<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks order: permintaan penunjang lab dan radiologi pasien.
 *
 * Satu siklus untuk kedua modalitas: diminta -> diproses -> hasil-tersedia
 * -> selesai (terverifikasi). Lab dan radiologi dipersatukan di sini karena
 * bentuk siklusnya identik; yang berbeda hanya bentuk hasilnya (kuantitatif
 * dengan angka dan rentang rujukan, kualitatif dengan nilai tetap, atau
 * naratif seperti laporan radiologi).
 *
 * Dua hal yang menentukan bentuk tabel:
 *
 *  1. Rujukan hasil disalin saat order dibuat, bukan dirujuk ke katalog.
 *     Rentang normal Hemoglobin bisa direvisi bertahun-tahun kemudian;
 *     hasil yang sudah tercatat tidak boleh ikut berubah maknanya.
 *
 *  2. Hasil terkunci begitu diverifikasi, sejalan dengan pola assessment
 *     final di konteks clinical - see FinalizeOrder di OrderService.
 */
return new class extends Migration
{
    private const S = 'orders';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . self::S);

        $this->createTestCatalog();
        $this->createOrders();
        $this->createOrderItems();
        $this->createOrderItemImages();
        $this->createSequences();
        $this->createPublishedViews();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_order_charge');

        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.order_item_images');
        Schema::dropIfExists(self::S . '.order_items');
        Schema::dropIfExists(self::S . '.orders');
        Schema::dropIfExists(self::S . '.test_catalog');
    }

    /**
     * Katalog pemeriksaan. Harga tunggal per pemeriksaan, sama seperti
     * pharmacy.drugs.sell_price - belum dibedakan per penjamin, konsisten
     * dengan simplifikasi Wave 1 yang sama di konteks pharmacy.
     */
    private function createTestCatalog(): void
    {
        Schema::create(self::S . '.test_catalog', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('category', 20)->comment('lab, radiologi');

            $table->string('specimen_type', 60)->nullable()->comment('Darah, urin, dahak - khusus lab');
            $table->string('modality', 60)->nullable()->comment('X-Ray, USG, CT-Scan - khusus radiologi');

            $table->string('result_type', 20)->comment('kuantitatif, kualitatif, naratif');
            $table->string('unit', 20)->nullable()->comment('Satuan hasil kuantitatif, mis. mg/dL');
            $table->decimal('reference_low', 10, 2)->nullable();
            $table->decimal('reference_high', 10, 2)->nullable();
            $table->string('reference_text', 100)->nullable()->comment('Nilai rujukan kualitatif, mis. Negatif');

            $table->decimal('price', 14, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();

            $table->timestampsTz();

            $table->index(['category', 'is_active']);
        });

        DB::statement("ALTER TABLE " . self::S . ".test_catalog ADD CONSTRAINT test_catalog_category_check
            CHECK (category IN ('lab','radiologi'))");
        DB::statement("ALTER TABLE " . self::S . ".test_catalog ADD CONSTRAINT test_catalog_result_type_check
            CHECK (result_type IN ('kuantitatif','kualitatif','naratif'))");

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX test_catalog_name_trgm_idx
            ON ' . self::S . '.test_catalog USING gin (name gin_trgm_ops)');
    }

    private function createOrders(): void
    {
        Schema::create(self::S . '.orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_number', 24)->unique();

            // Rujukan lintas konteks: id disimpan, foreign key tidak dibuat.
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            // Salinan untuk layar daftar.
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);
            $table->string('unit_name', 150)->nullable();

            $table->string('category', 20)->comment('lab, radiologi');

            $table->unsignedBigInteger('requesting_practitioner_id')->nullable();
            $table->string('requesting_practitioner_name', 150)->nullable();

            $table->text('clinical_notes')->nullable()->comment('Indikasi klinis / keterangan permintaan');

            $table->string('status', 20)->default('diminta')
                ->comment('diminta, diproses, hasil-tersedia, selesai, batal');

            $table->timestampTz('requested_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('resulted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verified_by_name', 150)->nullable();

            $table->text('cancellation_reason')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'requested_at']);
            $table->index(['registration_id']);
            $table->index(['patient_id', 'requested_at']);
            $table->index(['category', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".orders ADD CONSTRAINT orders_category_check
            CHECK (category IN ('lab','radiologi'))");
        DB::statement("ALTER TABLE " . self::S . ".orders ADD CONSTRAINT orders_status_check
            CHECK (status IN ('diminta','diproses','hasil-tersedia','selesai','batal'))");
    }

    private function createOrderItems(): void
    {
        Schema::create(self::S . '.order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('order_id')->constrained(self::S . '.orders')->cascadeOnDelete();
            $table->foreignId('test_id')->constrained(self::S . '.test_catalog');

            /*
             * Disalin saat order dibuat. Rentang rujukan dan harga di katalog
             * bisa direvisi kemudian; hasil yang sudah tercatat tidak boleh
             * ikut berubah maknanya.
             */
            $table->string('test_code', 20);
            $table->string('test_name', 150);
            $table->string('result_type', 20);
            $table->string('unit', 20)->nullable();
            $table->decimal('reference_low', 10, 2)->nullable();
            $table->decimal('reference_high', 10, 2)->nullable();
            $table->string('reference_text', 100)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);

            $table->decimal('result_numeric', 12, 2)->nullable();
            $table->string('result_text', 200)->nullable();
            $table->text('result_notes')->nullable()->comment('Laporan naratif radiologi / catatan tambahan lab');
            $table->boolean('is_abnormal')->default(false);

            $table->unsignedBigInteger('entered_by')->nullable();
            $table->string('entered_by_name', 150)->nullable();
            $table->timestampTz('entered_at')->nullable();

            $table->timestampsTz();

            $table->index('test_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".order_items ADD CONSTRAINT order_items_result_type_check
            CHECK (result_type IN ('kuantitatif','kualitatif','naratif'))");

        // Pemeriksaan yang sama tidak diminta dua kali dalam satu order.
        DB::statement('CREATE UNIQUE INDEX order_items_unique_test
            ON ' . self::S . '.order_items (order_id, test_id)');
    }

    /**
     * Gambar radiologi. Satu item bisa punya beberapa citra (mis. proyeksi
     * AP dan lateral untuk satu permintaan rontgen).
     */
    private function createOrderItemImages(): void
    {
        Schema::create(self::S . '.order_item_images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('order_item_id')->constrained(self::S . '.order_items')->cascadeOnDelete();

            $table->string('file_path', 500);
            $table->string('caption', 150)->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestampTz('uploaded_at');
        });
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
        // billing memakai ini untuk menarik biaya penunjang ke tagihan.
        DB::statement("CREATE VIEW " . self::S . ".v_order_charge AS
            SELECT i.id             AS item_id,
                   o.id             AS order_id,
                   o.registration_id,
                   o.patient_id,
                   o.order_number,
                   o.category,
                   o.verified_at,
                   i.test_code,
                   i.test_name,
                   i.unit_price,
                   i.unit_price     AS amount
            FROM " . self::S . ".orders o
            JOIN " . self::S . ".order_items i ON i.order_id = o.id
            WHERE o.status = 'selesai' AND o.deleted_at IS NULL");
    }
};
