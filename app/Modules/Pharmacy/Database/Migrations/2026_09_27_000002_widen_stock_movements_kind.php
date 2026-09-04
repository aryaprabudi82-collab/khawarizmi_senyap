<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * retur_ke_suplier butuh kind baru pada stock_movements: 'retur-keluar'
 * (barang cacat/salah kirim keluar gudang menuju suplier), beda dari
 * 'retur' yang sudah ada (stok MASUK kembali saat penyerahan ke pasien
 * dibatalkan) — arah berlawanan, disatukan label akan membingungkan
 * rekonsiliasi buku besar. Lihat StockLedger::deductFromBatch().
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.stock_movements DROP CONSTRAINT stock_movements_kind_check');
        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','retur-keluar','koreksi','kadaluarsa','rusak'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.stock_movements DROP CONSTRAINT stock_movements_kind_check');
        DB::statement("ALTER TABLE " . self::S . ".stock_movements ADD CONSTRAINT stock_movements_kind_check
            CHECK (kind IN ('masuk','keluar','retur','koreksi','kadaluarsa','rusak'))");
    }
};
