<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain I item C: komponen jasa medis pada tarif.
 *
 * Satu tarif bukan satu angka utuh — ia terbagi menjadi porsi yang jatuh
 * ke pihak berbeda, dan pembagian itulah dasar remunerasi dokter dan
 * paramedis. Enam komponen di bawah berpadanan persis dengan kolom
 * jns_perawatan Khanza, sekaligus dengan enam kelompok laporan domain I:
 *
 *   share_facility   <- material          (harian_js / bulanan_js)
 *   share_bhp        <- bhp               (harian_paket_bhp / bulanan_paket_bhp)
 *   share_doctor     <- tarif_tindakandr  (harian_dokter / bulanan_dokter)
 *   share_paramedic  <- tarif_tindakanpr  (harian_paramedis / bulanan_paramedis)
 *   share_kso        <- kso               (harian_kso / bulanan_kso)
 *   share_management <- menejemen         (harian_menejemen / bulanan_menejemen)
 *
 * Disimpan sebagai kolom, bukan tabel anak (dikonfirmasi user): himpunan
 * komponennya ditentukan kebijakan RS dan aturan remunerasi, bukan daftar
 * yang tumbuh sembarangan — Khanza sendiri memakai kolom tetap. Tarif di
 * sini sudah bitemporal, jadi perubahan komposisi otomatis berjejak tanpa
 * mekanisme tambahan.
 *
 * CHECK-nya sengaja mengizinkan SEMUA komponen nol: tarif yang belum
 * dirinci komponennya tetap sah dan tetap bisa ditagihkan. Yang dilarang
 * adalah rincian yang tidak menjumlah — kalau seseorang sudah mengisi
 * komponen, jumlahnya wajib sama dengan tarifnya, supaya tidak pernah ada
 * tarif yang pembagiannya diam-diam meleset dari yang ditagihkan.
 */
return new class extends Migration
{
    private const S = 'catalog';

    private const KOMPONEN = [
        'share_facility' => 'Jasa sarana — padanan material Khanza',
        'share_bhp' => 'Bahan habis pakai',
        'share_doctor' => 'Jasa dokter — dasar remunerasi',
        'share_paramedic' => 'Jasa paramedis',
        'share_kso' => 'Kerja sama operasional',
        'share_management' => 'Manajemen',
    ];

    public function up(): void
    {
        Schema::table(self::S . '.tariffs', function (Blueprint $table) {
            foreach (self::KOMPONEN as $kolom => $keterangan) {
                $table->decimal($kolom, 14, 2)->default(0)->comment($keterangan);
            }
        });

        $jumlah = implode(' + ', array_keys(self::KOMPONEN));

        DB::statement('ALTER TABLE ' . self::S . ".tariffs ADD CONSTRAINT tariffs_components_check
            CHECK (({$jumlah}) = 0 OR ({$jumlah}) = amount)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.tariffs DROP CONSTRAINT tariffs_components_check');

        Schema::table(self::S . '.tariffs', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::KOMPONEN));
        });
    }
};
