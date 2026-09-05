<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain G Khanza item C dari 4 sub-order yang disepakati —
 * inventaris_sirkulasi: riwayat perpindahan SATU aset antar ruang/
 * lokasi. Kode ini berdiri sendiri di xlsx map (tidak ada varian
 * saudara seperti sirkulasi_dapur/sirkulasi_dapur2 di domain E/F).
 *
 * Beda BENTUK dari StockLedger/stock_movements inventory/kitchen:
 * itu buku besar KUANTITAS barang fungibel, ini riwayat LOKASI satu
 * aset individual (asset_id, bukan item_id + quantity). Satu baris
 * di asset_transfers mewakili SATU perpindahan — konsisten dengan
 * pola "satu baris per kejadian" di seluruh sistem ini (mis.
 * asset.cssd_circulations, blood.blood_units). asset.assets.
 * location_id tetap jadi cache lokasi TERKINI (diperbarui tiap
 * perpindahan), riwayat lengkapnya ada di tabel ini — pola sama
 * dengan quantity_on_hand sebagai cache di inventory/kitchen.
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::create(self::S . '.asset_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('transfer_number', 24)->unique();
            $table->foreignId('asset_id')->constrained(self::S . '.assets');
            $table->foreignId('from_location_id')->nullable()->constrained(self::S . '.locations');
            $table->foreignId('to_location_id')->constrained(self::S . '.locations');

            $table->timestampTz('transferred_at');
            $table->unsignedBigInteger('transferred_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.asset_transfers');
    }
};
