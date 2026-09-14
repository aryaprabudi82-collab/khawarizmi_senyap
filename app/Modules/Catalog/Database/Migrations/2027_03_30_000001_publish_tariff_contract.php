<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan `catalog.v_tariff` — supaya CDM bisa MENUNJUK tarif
 * layanan tanpa memilikinya.
 *
 * MENGAPA VIEW, BUKAN MEMINDAHKAN TABELNYA.
 *
 * Discovery §10 sudah mengoreksi kesimpulan awal: `catalog.tariffs` SUDAH
 * bitemporal, sudah berdimensi penjamin dan kelas, dan resolusinya per
 * tanggal transaksi sudah berjalan lewat `TariffLookup` yang dipakai
 * billing setiap hari. Memindahkannya berarti membuang mekanisme yang
 * bekerja — persis yang dilarang Aturan Konsolidasi.
 *
 * Yang dibutuhkan keuangan bukan kepemilikan tabelnya, melainkan HAK
 * MEMBACANYA lewat kontrak yang stabil. Uji batas konteks melarang
 * `keuangan_master` menyentuh `catalog.tariffs` langsung, dan larangan
 * itu benar: pembacaan langsung berarti tiap perubahan kolom catalog
 * memutus keuangan tanpa ada yang memperingatkan.
 *
 * YANG SENGAJA IKUT DITERBITKAN: komponen jasa (share_*). Jaspel Wave 6
 * membutuhkannya, dan menerbitkannya sekarang mencegah kontrak kedua yang
 * hampir sama dibuat belakangan.
 *
 * YANG SENGAJA TIDAK: tidak ada agregasi, tidak ada "tarif berlaku hari
 * ini". View ini mengembalikan SELURUH baris berikut masa berlakunya, dan
 * penyaringan per tanggal dilakukan pemanggil. Alasannya sama dengan
 * `finance.v_account` yang tidak memuat saldo: begitu view memutuskan
 * "hari ini", tiap pemanggil yang butuh tanggal lain terpaksa mengakalinya
 * sendiri-sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE OR REPLACE VIEW catalog.v_tariff AS
            SELECT
                t.id                AS tariff_id,
                s.id                AS service_id,
                s.code              AS service_code,
                s.name              AS service_name,
                s.category          AS service_category,
                s.is_active         AS service_active,
                t.payer_id,
                t.care_class,
                t.amount,
                t.amount_returning,
                t.share_facility,
                t.share_bhp,
                t.share_doctor,
                t.share_paramedic,
                t.share_kso,
                t.share_management,
                t.valid_from,
                t.valid_until
              FROM catalog.tariffs t
              JOIN catalog.services s ON s.id = t.service_id
        ');

        DB::statement("COMMENT ON VIEW catalog.v_tariff IS
            'Kontrak terbitan: tarif layanan klinis berikut dimensi penjamin, kelas rawat, dan masa
             berlakunya. Penyaringan per tanggal dilakukan PEMANGGIL — view sengaja tidak memutuskan
             hari ini, supaya kunjungan lama bisa dinilai dengan tarif yang berlaku saat itu.'");

        /*
         * Layanan yang bisa ditagihkan, terlepas dari ada-tidaknya tarif.
         * Dibutuhkan penaut CDM: sebuah layanan yang BELUM bertarif tetap
         * harus punya kode CDM dan pemetaan akun — kalau tidak, saat
         * tarifnya diisi nanti, tagihan pertamanya langsung gagal
         * dijurnalkan dan tidak ada yang menyangka penyebabnya.
         */
        DB::statement('
            CREATE OR REPLACE VIEW catalog.v_service AS
            SELECT id, code, name, category, is_active
              FROM catalog.services
        ');

        DB::statement("COMMENT ON VIEW catalog.v_service IS
            'Kontrak terbitan: layanan klinis yang bisa ditagihkan. Dipakai CDM untuk menaut kode
             global dan pemetaan akun, termasuk untuk layanan yang tarifnya belum diisi.'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.v_tariff');
        DB::statement('DROP VIEW IF EXISTS catalog.v_service');
    }
};
