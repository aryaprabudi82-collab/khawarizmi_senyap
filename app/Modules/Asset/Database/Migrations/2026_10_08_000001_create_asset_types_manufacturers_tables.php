<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain G Khanza item A dari 4 sub-order yang disepakati — melengkapi
 * data master aset/inventaris. Konteks asset ditandai "Selesai" di
 * tracker sebelumnya ("seluruh 4 area kini tercakup") tapi diverifikasi
 * ulang: klaim itu benar untuk CSSD & kesling, tapi area aset/inventaris
 * sendiri masih longgar dari rantai pengadaan, sirkulasi antar lokasi,
 * dan pemeliharaan terjadwal — dikonfirmasi user (AskUserQuestion),
 * diperluas dengan rigor yang sama seperti domain D/E/F.
 *
 * inventaris_jenis dan inventaris_produsen: sebelumnya TIDAK ada tabel
 * master sama sekali (beda dari inventaris_kategori/inventaris_ruang
 * yang sudah punya asset.categories/asset.locations sejak Wave 1) —
 * ditambahkan di sini demi konsistensi, karena Khanza sendiri
 * memperlakukan jenis dan produsen sebagai menu master tersendiri,
 * bukan sekadar atribut. inventaris_merk TIDAK dapat tabel baru —
 * kolom teks bebas assets.brand yang sudah ada sejak awal dinilai
 * cukup (nama merk sangat bervariasi, tidak perlu governance ketat
 * seperti kategori/lokasi/jenis/produsen yang dipakai untuk
 * pengelompokan & pelaporan).
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::create(self::S . '.types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        Schema::create(self::S . '.manufacturers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
        });

        Schema::table(self::S . '.assets', function (Blueprint $table) {
            $table->foreignId('type_id')->nullable()->after('category_id')->constrained(self::S . '.types');
            $table->foreignId('manufacturer_id')->nullable()->after('brand')->constrained(self::S . '.manufacturers');
        });
    }

    public function down(): void
    {
        Schema::table(self::S . '.assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manufacturer_id');
            $table->dropConstrainedForeignId('type_id');
        });

        Schema::dropIfExists(self::S . '.manufacturers');
        Schema::dropIfExists(self::S . '.types');
    }
};
