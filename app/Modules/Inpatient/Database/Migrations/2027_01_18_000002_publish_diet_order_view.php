<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bangsal ikut diterbitkan pada order diet (domain O item D).
 *
 * Menaungi 4 kode grafik_porsidiet_pertanggal, _perbulan, _pertahun,
 * dan _perbangsal.
 *
 * KONTRAKNYA SUDAH ADA SEJAK DOMAIN J ITEM E, jadi yang dikerjakan di
 * sini MEMPERLUAS, bukan membuat baru — dan itu ketahuan justru karena
 * migrasi pertama saya gagal dengan "relation v_diet_order already
 * exists". Membuat kontrak kedua bernama lain akan menghasilkan dua
 * definisi order diet yang bisa berbeda penyaringnya.
 *
 * BANGSALNYA DISAMBUNGKAN DI KONTRAK. Order diet melekat pada admisi,
 * dan bangsalnya baru terbaca lewat tempat tidur dan ruangnya —
 * penggabungan tiga tabel yang tidak boleh ditemukan ulang tiap kali
 * ada yang ingin menghitung porsi per bangsal.
 *
 * SATU ORDER BUKAN SATU PORSI, dan itu perlu disebut supaya grafiknya
 * tidak dibaca keliru. Yang dihitung adalah ORDER DIET, bukan jumlah
 * nampan yang benar-benar diantar dapur — pasien yang dietnya berjalan
 * tujuh hari tetap satu order, dan kolom hari_diet yang sudah ada
 * sejak awal justru yang menjawab lamanya. Jumlah porsi harian yang
 * sesungguhnya menuntut pencatatan distribusi makan di konteks
 * kitchen, dan itu belum ada; grafik ini menjawab "berapa banyak
 * pasien sedang menjalani diet apa", bukan "berapa nampan yang keluar
 * dapur".
 *
 * KOLOM BARU DI UJUNG — CREATE OR REPLACE VIEW tidak bisa menyisipkan
 * kolom di tengah.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        $this->rebuild(true);
    }

    public function down(): void
    {
        $this->rebuild(false);
    }

    private function rebuild(bool $denganBangsal): void
    {
        $tambahan = $denganBangsal
            ? ',
                   a.patient_id,
                   r.room_number,
                   r.room_class,
                   r.unit_name AS ward_name'
            : '';

        $join = $denganBangsal
            ? '
              LEFT JOIN '.self::S.'.admissions a ON a.id = d.admission_id
              LEFT JOIN '.self::S.'.beds b       ON b.id = a.bed_id
              LEFT JOIN '.self::S.'.rooms r      ON r.id = b.room_id'
            : '';

        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_diet_order AS
            SELECT d.id, d.admission_id, d.diet_type, d.status,
                   d.start_date, d.end_date, d.ordered_by_name,
                   (coalesce(d.end_date, current_date) - d.start_date) + 1 AS hari_diet'.$tambahan.'
              FROM '.self::S.'.diet_orders d'.$join);
    }
};
