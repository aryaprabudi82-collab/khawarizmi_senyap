<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain D Khanza ("Farmasi & Inventory Medis"), item 1 dari 6 sub-order
 * yang disepakati — master data. 9 kode Khanza, package Java "inventory"
 * semua, context "pharmacy" di katalog (tidak salah-taut).
 *
 * obat sudah ada tabelnya (pharmacy.drugs, migrasi 2026_01_07) tapi belum
 * pernah punya layar CRUD — baru dibangun di sini lewat MasterDataController.
 * jenis_barang SUDAH terpenuhi oleh drugs.category (obat/bhp/alkes,
 * CHECK constraint) sejak awal — tidak jadi tabel referensi terpisah,
 * tidak ada tabel baru untuknya di migrasi ini.
 *
 * 7 kode sisanya genuinely tabel referensi baru: kategori_barang (kategori
 * terapi, mis. Antibiotik/Analgesik — beda konsep dari jenis_barang yang
 * cuma obat/bhp/alkes), golongan_barang (golongan regulasi resmi: Bebas/
 * Bebas Terbatas/Keras/Narkotika/Psikotropika — beda dari is_narcotic/
 * is_psychotropic yang sudah ada, itu flag pengawasan internal, ini
 * klasifikasi resmi untuk pelaporan), satuan_barang (kode akses yang SAMA
 * dipakai ulang di domain E/F/S Khanza — tapi context sungguhan "inventory"
 * (domain E, sudah dibangun) pakai kolom teks bebas unit_of_measure, bukan
 * tabel bersama; farmasi butuh multi-satuan asli untuk konversi_satuan,
 * makanya tetap dibangun sebagai tabel di sini, bukan referensi silang
 * antar-context), konversi_satuan (rasio antar-satuan per obat, mis. 1
 * boks = 10 strip = 100 tablet — dipakai nanti saat pengadaan/dispensing
 * dibangun, Wave 1 ini baru metadatanya), metode_racik (cara racik resep
 * puyer/kapsul racikan/dst.), suplier (distributor), industrifarmasi
 * (pabrikan/pemilik izin edar — beda pihak dari suplier).
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        $this->createDrugCategories();
        $this->createDrugClasses();
        $this->createUnits();
        $this->createSuppliers();
        $this->createManufacturers();
        $this->createCompoundingMethods();
        $this->widenDrugs();
        $this->createDrugUnits();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.drug_units');

        Schema::table(self::S . '.drugs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('drug_category_id');
            $table->dropConstrainedForeignId('drug_class_id');
            $table->dropConstrainedForeignId('manufacturer_id');
        });

        Schema::dropIfExists(self::S . '.compounding_methods');
        Schema::dropIfExists(self::S . '.manufacturers');
        Schema::dropIfExists(self::S . '.suppliers');
        Schema::dropIfExists(self::S . '.units');
        Schema::dropIfExists(self::S . '.drug_classes');
        Schema::dropIfExists(self::S . '.drug_categories');
    }

    /** kategori_barang — kategori terapi (Antibiotik, Analgesik, dst.), bebas ditambah admin farmasi. */
    private function createDrugCategories(): void
    {
        Schema::create(self::S . '.drug_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /** golongan_barang — golongan regulasi resmi obat (bukan flag internal is_narcotic/is_psychotropic). */
    private function createDrugClasses(): void
    {
        Schema::create(self::S . '.drug_classes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /** satuan_barang — vokabuler satuan (tablet, strip, boks, botol, ampul, dst.). */
    private function createUnits(): void
    {
        Schema::create(self::S . '.units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 40);
            $table->timestampsTz();
        });
    }

    /** suplier — distributor tempat RS membeli, beda pihak dari pabrikan (industrifarmasi). */
    private function createSuppliers(): void
    {
        Schema::create(self::S . '.suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('license_number', 60)->nullable()->comment('No. izin PBF/distributor');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /** industrifarmasi — pabrikan/pemegang izin edar. */
    private function createManufacturers(): void
    {
        Schema::create(self::S . '.manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('license_number', 60)->nullable()->comment('No. izin industri farmasi');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /** metode_racik — cara racik resep (puyer, kapsul racikan, sirup racikan, dst.). */
    private function createCompoundingMethods(): void
    {
        Schema::create(self::S . '.compounding_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    private function widenDrugs(): void
    {
        Schema::table(self::S . '.drugs', function (Blueprint $table) {
            $table->foreignId('drug_category_id')->nullable()->after('category')
                ->constrained(self::S . '.drug_categories')->nullOnDelete();
            $table->foreignId('drug_class_id')->nullable()->after('drug_category_id')
                ->constrained(self::S . '.drug_classes')->nullOnDelete();
            $table->foreignId('manufacturer_id')->nullable()->after('drug_class_id')
                ->constrained(self::S . '.manufacturers')->nullOnDelete();
        });
    }

    /**
     * konversi_satuan — rasio tiap satuan tambahan terhadap satuan dasar
     * obat (drugs.unit, dianggap conversion_to_base = 1 dan tidak perlu
     * baris di sini). Wave 1: metadata saja, belum dipakai menghitung
     * stok/pengadaan otomatis — itu menyusul saat rantai pengadaan &
     * stok/batch dibangun (item 2 & 3 sub-order domain D).
     */
    private function createDrugUnits(): void
    {
        Schema::create(self::S . '.drug_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drug_id')->constrained(self::S . '.drugs')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained(self::S . '.units')->restrictOnDelete();
            $table->decimal('conversion_to_base', 12, 4)->comment('1 satuan ini = N satuan dasar (drugs.unit)');
            $table->boolean('is_purchase_unit')->default(false)->comment('Satuan default saat pengadaan');
            $table->boolean('is_dispense_unit')->default(false)->comment('Satuan default saat penyerahan ke pasien');
            $table->timestampsTz();

            $table->unique(['drug_id', 'unit_id']);
        });

        DB::statement('ALTER TABLE ' . self::S . '.drug_units ADD CONSTRAINT drug_units_conversion_positive_check
            CHECK (conversion_to_base > 0)');
    }
};
