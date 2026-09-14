<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan harga obat — `pharmacy.v_drug_price` dan
 * `pharmacy.v_drug_markup`.
 *
 * DUA VIEW, BUKAN SATU, DAN ITU DISENGAJA. Harga jual obat adalah HARGA
 * DASAR dikali MARKUP yang berlaku bagi penjaminnya. Menggabungkan
 * keduanya jadi satu view berarti view itu harus melakukan perkalian —
 * dan perkalian uang di dalam SQL berarti pembulatannya diputuskan
 * PostgreSQL, di luar `Money`, dengan aturan yang tidak sama dengan
 * aturan yang dipakai sisa sistem. Dua tempat membulatkan uang dengan
 * cara berbeda adalah persis bagaimana selisih sen tumbuh tanpa ada yang
 * bisa menunjuk penyebabnya.
 *
 * Maka view menerbitkan BAHANNYA; perkaliannya dilakukan
 * `PharmacyTariffResolver` lewat `Money`, satu tempat, satu aturan.
 *
 * CATATAN TEMUAN: `pharmacy.drug_markups` punya model lengkap berikut
 * aturan resolusinya (`DrugMarkup::berlaku()`) tetapi TIDAK ADA SATU PUN
 * kode yang memanggilnya, dan tabelnya kosong. Harga obat hari ini adalah
 * `sell_price` polos. Artinya seluruh penjamin ditagih harga yang sama —
 * termasuk penjamin yang kontraknya menetapkan markup berbeda. Resolver
 * ini memakai markup sejak awal supaya saat tabelnya diisi, harga langsung
 * benar tanpa ada kode yang perlu diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE OR REPLACE VIEW pharmacy.v_drug_price AS
            SELECT
                d.id         AS drug_id,
                d.code       AS drug_code,
                d.name       AS drug_name,
                d.category,
                d.sell_price AS base_price,
                d.is_active
              FROM pharmacy.drugs d
        ');

        DB::statement("COMMENT ON VIEW pharmacy.v_drug_price IS
            'Kontrak terbitan: harga DASAR obat berikut KATEGORI-nya (obat/bhp/alkes). Harga jual
             sebenarnya = harga dasar x markup penjamin (lihat v_drug_markup). Perkaliannya sengaja
             TIDAK dilakukan di view: pembulatan uang harus terjadi di satu tempat, yaitu Money, bukan
             tersebar antara SQL dan PHP. Kategori ikut karena ia menentukan GOLONGAN CDM, dan golongan
             menentukan apakah item wajib punya akun beban pokok — menganggap seluruh isi farmasi
             sebagai obat membuat kasa dan spuit menuntut akun HPP obat.'");

        DB::statement('
            CREATE OR REPLACE VIEW pharmacy.v_drug_markup AS
            SELECT
                m.id,
                m.payer_id,
                m.room_class,
                m.markup_percent,
                m.effective_from,
                m.effective_until
              FROM pharmacy.drug_markups m
        ');

        DB::statement("COMMENT ON VIEW pharmacy.v_drug_markup IS
            'Kontrak terbitan: markup harga obat per penjamin dan (opsional) kelas rawat, berperiode.
             room_class NULL berarti berlaku untuk SEMUA kelas; baris berkelas mengalahkan baris umum.'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS pharmacy.v_drug_price');
        DB::statement('DROP VIEW IF EXISTS pharmacy.v_drug_markup');
    }
};
