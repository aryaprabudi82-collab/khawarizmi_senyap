<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan harga barang koperasi — `retail.v_product_price`.
 *
 * TEMUAN YANG IKUT DICATAT DI SINI. `Retail\ProductService::hitungHarga()`
 * menghitung harga jual dengan `round((float) base_cost * (1 + markup/100), 2)`
 * — float, untuk uang, persis yang dilarang BAGIAN 3. Tabelnya sendiri
 * `numeric(14,2)`, jadi pelanggarannya ada di PHP, bukan di skema.
 *
 * TIDAK DIPERBAIKI SEKARANG, DAN ITU KEPUTUSAN SADAR: `Retail\*`
 * dijadwalkan pindah ke `keuangan/inventory-costing` pada Wave 5, dan
 * memperbaikinya sekarang berarti menyentuh modul yang akan dibongkar —
 * pekerjaan yang dilakukan dua kali. Yang penting adalah ia TERCATAT dan
 * tidak menular: resolver keuangan membaca `price` yang sudah tersimpan
 * di basis data sebagai string lewat `Money`, tidak ikut menghitungnya
 * dengan float. Dicatat sebagai utang teknis di MIGRATION-MAP Wave 5.
 *
 * Retail masih kosong (0 produk, 0 tingkat harga). View ini dibuat
 * sekarang supaya penaut CDM punya bentuk yang tetap saat datanya diisi —
 * bukan supaya ada yang bisa dibaca hari ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE OR REPLACE VIEW retail.v_product_price AS
            SELECT
                p.id        AS product_id,
                p.code      AS product_code,
                p.name      AS product_name,
                p.unit,
                p.base_cost,
                p.is_active,
                pp.price_tier_id,
                t.code      AS price_tier_code,
                t.name      AS price_tier_name,
                pp.price
              FROM retail.products p
              LEFT JOIN retail.product_prices pp ON pp.product_id = p.id
              LEFT JOIN retail.price_tiers  t   ON t.id = pp.price_tier_id
        ');

        DB::statement("COMMENT ON VIEW retail.v_product_price IS
            'Kontrak terbitan: barang koperasi berikut harga per tingkat harga. LEFT JOIN disengaja —
             barang yang belum berharga tetap harus punya kode CDM dan pemetaan akun, supaya saat
             harganya diisi nanti penjualan pertamanya tidak langsung gagal dijurnalkan.'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS retail.v_product_price');
    }
};
