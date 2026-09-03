<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pemeriksaan_lab_pa (Khanza domain A, "Periksa Lab PA" / DlgCariPeriksaLabPA)
 * — tidak punya paket Java override di Khanza_Functional_Dependency_Map.xlsx
 * (kosong, sama seperti periksa_lab/periksa_radiologi), jadi tetap di
 * konteks 'order' yang sudah menaungi periksa_lab/periksa_radiologi — siklus
 * permintaan->proses->hasil->verifikasi-nya identik, cuma kategori
 * pemeriksaannya beda (Patologi Anatomi: histopatologi, sitologi, FNAB).
 *
 * Bukan tabel baru — cuma menambah nilai kategori 'pa' ke CHECK constraint
 * yang sudah ada di orders.test_catalog dan orders.orders. Hasil PA memang
 * naratif (result_type sudah punya nilai 'naratif' sejak awal, dipakai
 * radiologi) sehingga tidak perlu kolom baru untuk itu.
 */
return new class extends Migration
{
    private const S = 'orders';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.test_catalog DROP CONSTRAINT test_catalog_category_check');
        DB::statement("ALTER TABLE " . self::S . ".test_catalog ADD CONSTRAINT test_catalog_category_check
            CHECK (category IN ('lab','radiologi','pa'))");

        DB::statement('ALTER TABLE ' . self::S . '.orders DROP CONSTRAINT orders_category_check');
        DB::statement("ALTER TABLE " . self::S . ".orders ADD CONSTRAINT orders_category_check
            CHECK (category IN ('lab','radiologi','pa'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.orders DROP CONSTRAINT orders_category_check');
        DB::statement("ALTER TABLE " . self::S . ".orders ADD CONSTRAINT orders_category_check
            CHECK (category IN ('lab','radiologi'))");

        DB::statement('ALTER TABLE ' . self::S . '.test_catalog DROP CONSTRAINT test_catalog_category_check');
        DB::statement("ALTER TABLE " . self::S . ".test_catalog ADD CONSTRAINT test_catalog_category_check
            CHECK (category IN ('lab','radiologi'))");
    }
};
