<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Markup harga jual obat per penjamin (Khanza `set_harga_obat_ralan` dan
 * `set_harga_obat_ranap`, domain U).
 *
 * SATU TABEL, BUKAN DUA. Khanza memisahkan `set_harga_obat_ralan` (berkunci
 * kd_pj) dari `set_harga_obat_ranap` (berkunci kd_pj + kelas). Dua tabel
 * untuk satu aturan — "berapa markup obat bagi penjamin ini" — berarti
 * setiap perhitungan harga harus tahu lebih dulu ia sedang melayani rawat
 * jalan atau rawat inap, lalu membaca tabel yang berbeda. Yang lupa
 * salah satunya tidak melempar galat; ia jatuh ke harga bawaan dan menagih
 * angka yang salah dengan tenang. Bentuk kekurangan yang sama sudah
 * ditemui pada tokopenjualan-vs-tokopiutang (domain S) dan buku-vs-ebook
 * (domain Q).
 *
 * KELAS RAWAT DIKUNCI DI TIPE KOLOM PADA KHANZA:
 * enum('Kelas 1','Kelas 2','Kelas 3','Kelas Utama','Kelas VIP','Kelas VVIP').
 * Sistem ini sudah punya daftar kelasnya sendiri di inpatient.rooms
 * (vip, kelas-1, kelas-2, kelas-3, icu, isolasi) — dan perhatikan bahwa
 * ICU dan ISOLASI tidak ada di daftar Khanza sama sekali, padahal keduanya
 * kelas rawat yang tarif obatnya justru paling sering berbeda.
 *
 * KELAS BOLEH KOSONG, DAN ARTINYA "BERLAKU UNTUK SEMUA KELAS" — termasuk
 * rawat jalan, yang memang tidak punya kelas. Itulah yang menyatukan kedua
 * tabel Khanza: baris tanpa kelas adalah `set_harga_obat_ralan`.
 *
 * MARKUP BERLAKU SEJAK TANGGAL. Harga jual obat ikut tagihan, dan tagihan
 * lampau tidak boleh berubah sendiri saat markupnya disesuaikan. Aturan
 * yang sama dipakai tarif embalase (item A) dan biaya harian kamar.
 *
 * LAHIR KOSONG. Besaran markup adalah kebijakan RSP UI yang terikat
 * kontrak dengan tiap penjamin; menebaknya berarti menagih penjamin dengan
 * angka yang tidak pernah disepakati siapa pun dalam kontrak mana pun.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    private const KELAS = ['vip', 'kelas-1', 'kelas-2', 'kelas-3', 'icu', 'isolasi'];

    public function up(): void
    {
        Schema::create(self::S.'.drug_markups', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Rujukan longgar ke catalog.payers — konteks lain, jadi tidak
             * ada foreign key yang menyeberang schema. Kodenya ikut disalin
             * supaya layar harga bisa menyebut penjaminnya tanpa menyeberang
             * batas untuk sekadar menampilkan satu nama.
             */
            $table->unsignedBigInteger('payer_id');
            $table->string('payer_code', 20);

            /*
             * Kosong berarti BERLAKU UNTUK SEMUA KELAS, termasuk rawat jalan
             * yang memang tidak punya kelas. Inilah yang menyatukan dua tabel
             * Khanza jadi satu.
             */
            $table->string('room_class', 20)->nullable();

            // Persen dari harga dasar. Nol adalah nilai yang sah dan
            // BERBEDA dari belum ditetapkan — nol berarti dijual sesuai
            // harga dasar, dan itu keputusan.
            $table->decimal('markup_percent', 6, 2);

            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['payer_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE '.self::S.".drug_markups ADD CONSTRAINT drug_markups_class_check
            CHECK (room_class IS NULL OR room_class IN ('".implode("','", self::KELAS)."'))");

        DB::statement('ALTER TABLE '.self::S.'.drug_markups
            ADD CONSTRAINT drug_markups_percent_check CHECK (markup_percent >= 0)');

        DB::statement('ALTER TABLE '.self::S.'.drug_markups
            ADD CONSTRAINT drug_markups_period_check
            CHECK (effective_until IS NULL OR effective_until >= effective_from)');

        /*
         * Satu markup berjalan per penjamin per kelas. Dua indeks parsial
         * karena NULL tidak sama dengan NULL di indeks unik PostgreSQL:
         * tanpa yang kedua, dua baris "semua kelas" untuk satu penjamin
         * akan lolos — dan itu justru baris yang paling sering dipakai.
         */
        DB::statement('CREATE UNIQUE INDEX drug_markups_berjalan_kelas_unique
            ON '.self::S.'.drug_markups (payer_id, room_class)
            WHERE effective_until IS NULL AND room_class IS NOT NULL');

        DB::statement('CREATE UNIQUE INDEX drug_markups_berjalan_umum_unique
            ON '.self::S.'.drug_markups (payer_id)
            WHERE effective_until IS NULL AND room_class IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.drug_markups');
    }
};
