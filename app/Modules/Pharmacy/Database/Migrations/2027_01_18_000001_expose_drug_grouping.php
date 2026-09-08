<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pengelompokan obat ikut diterbitkan (domain O item D).
 *
 * Menaungi 4 kode item_apotek_jenis, _kategori, _golongan, dan
 * _industrifarmasi. Ketiga tautannya — kategori, golongan, dan
 * produsen — sudah ada di pharmacy.drugs sebagai foreign key tapi
 * tidak pernah diterbitkan, sehingga konsumen cuma bisa melihat bentuk
 * sediaan dan penanda narkotika.
 *
 * NAMA, BUKAN ID. Grafik yang mengelompokkan menurut drug_category_id
 * akan menampilkan batang berlabel 3, 7, dan 12 — dan tidak ada yang
 * bisa membacanya. Penggabungannya dilakukan di kontrak supaya tiap
 * konsumen tidak menemukan ulang join yang sama.
 *
 * KOLOM BARU DI UJUNG. CREATE OR REPLACE VIEW tidak bisa menyisipkan
 * kolom di tengah, dan gagal dengan pesan yang menunjuk kolom lain —
 * pelajaran yang sudah dua kali terulang di proyek ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild(true);
    }

    public function down(): void
    {
        $this->rebuild(false);
    }

    private function rebuild(bool $denganPengelompokan): void
    {
        $tambahan = $denganPengelompokan
            ? ',
                c.name      AS category_name,
                k.name      AS class_name,
                m.name      AS manufacturer_name'
            : '';

        $join = $denganPengelompokan
            ? '
              LEFT JOIN pharmacy.drug_categories c ON c.id = d.drug_category_id
              LEFT JOIN pharmacy.drug_classes k    ON k.id = d.drug_class_id
              LEFT JOIN pharmacy.manufacturers m   ON m.id = d.manufacturer_id'
            : '';

        DB::statement('CREATE OR REPLACE VIEW pharmacy.v_drug_catalog AS
            SELECT
                d.id          AS drug_id,
                d.code        AS drug_code,
                d.name        AS drug_name,
                d.generic_name,
                d.kfa_code,
                d.form,
                d.strength,
                d.unit,
                d.is_narcotic,
                d.is_psychotropic,
                d.is_high_alert,
                d.is_active'.$tambahan.'
            FROM pharmacy.drugs d'.$join);
    }
};
