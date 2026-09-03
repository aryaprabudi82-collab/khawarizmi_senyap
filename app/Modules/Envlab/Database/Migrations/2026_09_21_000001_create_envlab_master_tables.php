<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks envlab: laboratorium kesehatan lingkungan & K3 (Khanza domain B,
 * "Barcode & Lab Kesling"). Bukan tentang pasien — pelanggannya bisa
 * internal (mis. bagian K3RS/kesling RS sendiri, memeriksa air limbah/udara
 * ruang operasi) atau eksternal (perusahaan/instansi lain yang menitipkan
 * sampel). Kelasnya semua berpaket Java "viabarcode" (satu paket untuk
 * seluruh grup menu domain B, bukan penanda per kode seperti domain A) —
 * lihat Khanza_Functional_Dependency_Map.xlsx sheet2 baris 37-50.
 *
 * Empat master data ini (pelanggan, jenis sampel, parameter pengujian,
 * nilai baku mutu) sengaja dipisah tiga tabel bukan satu tabel gabungan
 * seperti orders.test_catalog — satu parameter (mis. pH) dipakai lintas
 * banyak jenis sampel (air bersih, air limbah, dst.), masing-masing dengan
 * ambang baku mutu yang beda, jadi hubungan sampel<->parameter<->nilai
 * genuinely many-to-many, bukan satu baris katalog per pemeriksaan seperti
 * order/lab pasien.
 */
return new class extends Migration
{
    private const S = 'envlab';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . self::S);

        $this->createCustomers();
        $this->createSampleTypes();
        $this->createTestParameters();
        $this->createQualityStandards();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.quality_standards');
        Schema::dropIfExists(self::S . '.test_parameters');
        Schema::dropIfExists(self::S . '.sample_types');
        Schema::dropIfExists(self::S . '.customers');
    }

    /** pelanggan_lab_kesehatan_lingkungan */
    private function createCustomers(): void
    {
        Schema::create(self::S . '.customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('kind', 20)->comment('internal, eksternal');
            $table->string('address', 255)->nullable();
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".customers ADD CONSTRAINT customers_kind_check
            CHECK (kind IN ('internal','eksternal'))");
    }

    /** master_sampel_bakumutu */
    private function createSampleTypes(): void
    {
        Schema::create(self::S . '.sample_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('category', 30)->comment('air-bersih, air-limbah, udara-ambien, udara-ruangan, makanan-minuman, usap-alat, usap-dinding, lainnya');
            $table->string('regulatory_reference', 200)->nullable()->comment('mis. PP No. 22 Tahun 2021');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".sample_types ADD CONSTRAINT sample_types_category_check
            CHECK (category IN ('air-bersih','air-limbah','udara-ambien','udara-ruangan','makanan-minuman','usap-alat','usap-dinding','lainnya'))");
    }

    /** parameter_pengujian_lab_kesehatan_lingkungan */
    private function createTestParameters(): void
    {
        Schema::create(self::S . '.test_parameters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('unit', 30)->nullable()->comment('mis. mg/L, CFU/100mL, dB(A) - kosong untuk parameter kualitatif');
            $table->string('test_method', 150)->nullable()->comment('mis. SNI 6989.72:2009');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /**
     * nilai_normal_baku_mutu_lab_kesehatan_lingkungan — ambang baku mutu per
     * kombinasi jenis sampel + parameter. min/max untuk parameter kuantitatif,
     * qualitative_standard (mis. "Negatif") untuk yang bukan angka, pola sama
     * dengan orders.test_catalog (reference_low/high vs reference_text).
     */
    private function createQualityStandards(): void
    {
        Schema::create(self::S . '.quality_standards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sample_type_id');
            $table->unsignedBigInteger('parameter_id');
            $table->decimal('min_value', 12, 4)->nullable();
            $table->decimal('max_value', 12, 4)->nullable();
            $table->string('qualitative_standard', 100)->nullable();
            $table->string('regulatory_reference', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('sample_type_id')->references('id')->on(self::S . '.sample_types');
            $table->foreign('parameter_id')->references('id')->on(self::S . '.test_parameters');
            $table->unique(['sample_type_id', 'parameter_id']);
        });
    }
};
