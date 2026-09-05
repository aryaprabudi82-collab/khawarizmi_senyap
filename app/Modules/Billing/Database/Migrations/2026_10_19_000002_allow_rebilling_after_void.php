<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki jalan buntu: kunjungan yang tagihannya pernah dibatalkan
 * tidak bisa ditagih lagi selamanya.
 *
 * billing.invoices.registration_id memakai unique TANPA SYARAT, sementara
 * openInvoice() mengembalikan tagihan yang sudah ada tanpa memeriksa
 * statusnya. Jadi begitu satu tagihan di-void — misalnya karena salah
 * penjamin atau salah kunjungan — kasir selamanya mendapat tagihan void
 * itu kembali, dan penggantinya tidak bisa dibuat karena ditolak indeks
 * unik. Kunjungan pasiennya jadi mustahil ditagih.
 *
 * Ini kelas kesalahan yang sama seperti yang sudah ditemukan pada piutang
 * pasien: setiap entitas yang bisa dibatalkan lalu dibuat ulang butuh
 * unique PARSIAL, bukan unique biasa. Pola yang sama sudah dipakai sesi
 * parkir yang masih terbuka, piutang yang masih berlaku, dan penutupan
 * periode yang belum dibuka kembali.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        /*
         * Nama constraint-nya BERPREFIKS SCHEMA karena koneksi pgsql memakai
         * prefix_indexes — jadi 'billing_invoices_...', bukan 'invoices_...'.
         * Versi pertama migrasi ini menjatuhkan nama yang salah, dan karena
         * memakai IF EXISTS ia gagal tanpa bersuara: indeks lama tetap ada dan
         * jalan buntunya tidak benar-benar hilang. Pengujiannya yang menangkap.
         * Kedua nama dicoba supaya migrasi ini tetap benar di basis data yang
         * dibuat sebelum maupun sesudah prefix itu berlaku.
         */
        DB::statement('ALTER TABLE ' . self::S . '.invoices DROP CONSTRAINT IF EXISTS billing_invoices_registration_id_unique');
        DB::statement('ALTER TABLE ' . self::S . '.invoices DROP CONSTRAINT IF EXISTS invoices_registration_id_unique');

        DB::statement('CREATE UNIQUE INDEX invoices_registration_aktif_unique
            ON ' . self::S . ".invoices (registration_id)
            WHERE status <> 'void'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::S . '.invoices_registration_aktif_unique');

        DB::statement('ALTER TABLE ' . self::S . '.invoices ADD CONSTRAINT billing_invoices_registration_id_unique UNIQUE (registration_id)');
    }
};
