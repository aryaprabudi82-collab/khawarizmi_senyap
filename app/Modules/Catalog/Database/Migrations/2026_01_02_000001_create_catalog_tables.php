<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks catalog: penjamin, layanan, dan tarif.
 *
 * Data referensi yang dihargai dan ditagihkan. Jarang berubah, banyak dibaca.
 */
return new class extends Migration
{
    private const S = 'catalog';

    public function up(): void
    {
        // Penjamin pembiayaan: umum, BPJS, asuransi, perusahaan.
        Schema::create(self::S . '.payers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('kind', 20)->comment('umum, bpjs, asuransi, perusahaan');
            $table->string('company_name', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".payers ADD CONSTRAINT payers_kind_check
            CHECK (kind IN ('umum','bpjs','asuransi','perusahaan'))");

        // Layanan yang bisa ditagihkan. Tarif registrasi termasuk di sini.
        Schema::create(self::S . '.services', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('category', 30)->index()->comment('registrasi, konsultasi, tindakan, penunjang');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        /*
         * Tarif berlaku per kombinasi layanan, penjamin, dan kelas, dengan masa
         * berlaku. Tarif lama tidak pernah ditimpa — kunjungan bulan lalu harus
         * tetap bisa dihitung ulang dengan tarif yang berlaku saat itu.
         */
        Schema::create(self::S . '.tariffs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('service_id')->constrained(self::S . '.services')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained(self::S . '.payers')->cascadeOnDelete();
            $table->string('care_class', 20)->default('-')->comment('-, kelas-1, kelas-2, kelas-3, vip, vvip');

            $table->decimal('amount', 14, 2);
            $table->decimal('amount_returning', 14, 2)->nullable()->comment('Tarif pasien lama bila berbeda');

            $table->date('valid_from');
            $table->date('valid_until')->nullable()->comment('Null berarti masih berlaku');

            $table->timestampsTz();

            $table->index(['service_id', 'payer_id', 'care_class', 'valid_from'], 'tariffs_lookup_idx');
        });

        // Mencegah dua tarif aktif untuk kombinasi yang sama pada tanggal yang sama.
        DB::statement('CREATE UNIQUE INDEX tariffs_active_unique
            ON ' . self::S . '.tariffs (service_id, payer_id, care_class)
            WHERE valid_until IS NULL');

        $this->createPublishedViews();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_payer_summary');

        Schema::dropIfExists(self::S . '.tariffs');
        Schema::dropIfExists(self::S . '.services');
        Schema::dropIfExists(self::S . '.payers');
    }

    /**
     * Kontrak baca untuk konteks lain.
     */
    private function createPublishedViews(): void
    {
        // billing memakai 'kind' untuk menentukan siapa yang menanggung tagihan:
        // penjamin 'umum' membayar langsung di kasir, selain itu ditagihkan ke
        // penjamin (piutang) - alur klaimnya sendiri menyusul di domain finance.
        DB::statement("CREATE VIEW " . self::S . ".v_payer_summary AS
            SELECT id, code, name, kind, is_active
            FROM " . self::S . ".payers
            WHERE is_active = true");
    }
};
