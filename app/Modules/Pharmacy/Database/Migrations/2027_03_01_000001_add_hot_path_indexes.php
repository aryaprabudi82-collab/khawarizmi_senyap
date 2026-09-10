<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indeks jalur panas farmasi (kesiapan 2.000 pasien/hari).
 *
 * POSTGRESQL TIDAK MENGINDEKS KOLOM FOREIGN KEY SECARA OTOMATIS. Ini
 * berbeda dari MySQL/InnoDB, dan perbedaannya tidak terasa sama sekali di
 * basis data pengembangan yang berisi seratus baris. Akibatnya dua, dan
 * keduanya baru muncul di volume sungguhan:
 *
 *  1. Memuat baris anak dari induknya — `$resep->items` — memindai SELURUH
 *     tabel anak. Pada 2.000 pasien/hari dengan rata-rata dua resep dan
 *     empat baris per resep, `prescription_items` tumbuh sekitar enam juta
 *     baris setahun. Membuka satu resep berarti memindai keenam jutanya.
 *
 *  2. Memperbarui atau menghapus baris induk memindai seluruh tabel anak
 *     untuk memeriksa rujukan, SAMBIL MEMEGANG KUNCI. Di jam poliklinik
 *     penuh, itu bukan sekadar lambat — ia menahan transaksi lain.
 *
 * YANG DIPILIH DAN YANG TIDAK. Indeks bukan gratis: tiap indeks memperlambat
 * setiap INSERT dan UPDATE, dan pada beban 2.000 pasien/hari tulisnya juga
 * padat. Yang diindeks di sini hanya kolom pada tabel yang TUMBUH MENGIKUTI
 * JUMLAH PASIEN. Foreign key ke tabel master yang isinya tetap beberapa ribu
 * baris (drug_category_id, manufacturer_id, unit_id) sengaja DIBIARKAN —
 * memindai tabel sepuluh ribu baris murah, dan indeksnya akan menagih biaya
 * tulis setiap hari untuk keuntungan yang tidak pernah terasa.
 *
 * DIPERIKSA DULU, BUKAN DITAMBAH MEMBABI BUTA. Sebelum menulis migrasi ini
 * katalog indeks yang sudah ada dibaca lebih dulu: trigram pencarian obat dan
 * indeks (status, prescribed_at) ternyata SUDAH dibuat sejak migrasi pertama
 * farmasi, jadi tidak diduplikasi di sini. Indeks kedua di atas kolom yang
 * sudah terindeks bukan sekadar mubazir — ia menagih biaya tulis setiap hari
 * selamanya untuk pembacaan yang sudah cepat.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    /**
     * Kolom anak yang tumbuh mengikuti jumlah pasien.
     *
     * @var array<string, string>
     */
    private const INDEKS = [
        // Dibuka setiap kali resep ditampilkan, ditelaah, atau diserahkan.
        // Inilah yang paling menentukan: ~6 juta baris setahun.
        'prescription_items' => 'prescription_id',

        // Laporan pemakaian obat & penelusuran batch per lokasi.
        'prescriptions' => 'location_id',
        'stock_batches' => 'location_id',

        // Penjualan bebas apotek — tumbuh harian.
        'retail_sale_items' => 'drug_id',
        'retail_sale_returns' => 'sale_id',
        'retail_sale_return_items' => 'sale_item_id',

        // Permintaan ruangan & pasien: satu baris per obat per permintaan,
        // dan permintaannya harian per bangsal.
        'ward_stock_request_items' => 'drug_id',
        'patient_stock_request_items' => 'drug_id',
        'patient_drug_return_items' => 'drug_id',
        'procedure_bhp_usage_items' => 'drug_id',
    ];

    public function up(): void
    {
        foreach (self::INDEKS as $tabel => $kolom) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s_%s_idx ON %s.%s (%s)',
                $tabel, $kolom, self::S, $tabel, $kolom
            ));
        }

        /*
         * substituted_from_drug_id hampir selalu KOSONG — ia hanya terisi
         * saat apoteker mengganti obat. Indeks parsial hanya mengindeks
         * baris yang terisi, jadi ukurannya sepersekian dan biaya tulisnya
         * nyaris nol untuk resep biasa. Indeks penuh atas kolom yang 99%
         * NULL adalah biaya tulis harian demi baris yang tak pernah dicari.
         */
        DB::statement('CREATE INDEX IF NOT EXISTS prescription_items_substitusi_idx
            ON '.self::S.'.prescription_items (substituted_from_drug_id)
            WHERE substituted_from_drug_id IS NOT NULL');

        /*
         * YANG SENGAJA TIDAK DITAMBAHKAN DI SINI, karena sudah ada sejak
         * migrasi pertama farmasi — dan diperiksa, bukan diasumsikan:
         *
         *  - `drugs_name_trgm_idx` dan `drugs_generic_trgm_idx` (GIN
         *    trigram untuk `ilike '%kata%'`);
         *  - `prescriptions (status, prescribed_at)`, yang melayani layar
         *    antrean apotek.
         *
         * Menambahkan indeks kedua di atas kolom yang sudah terindeks bukan
         * sekadar mubazir: ia menagih biaya tulis setiap hari selamanya
         * untuk pembacaan yang sudah cepat.
         */
    }

    public function down(): void
    {
        foreach (self::INDEKS as $tabel => $kolom) {
            DB::statement(sprintf('DROP INDEX IF EXISTS %s.%s_%s_idx', self::S, $tabel, $kolom));
        }

        DB::statement('DROP INDEX IF EXISTS '.self::S.'.prescription_items_substitusi_idx');
    }
};
