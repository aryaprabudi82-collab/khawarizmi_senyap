<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog perpustakaan (domain Q item A) — konteks baru `library`.
 *
 * Tujuh kode: ruang, kategori, jenis, pengarang, penerbit koleksi, koleksi
 * perpustakaan, dan koleksi ebook.
 *
 * EBOOK ADALAH MEDIUM SEBUAH KOLEKSI, BUKAN TABEL KEDUA.
 *
 * `perpustakaan_ebook` Khanza menyalin `perpustakaan_buku` nyaris kolom
 * per kolom: judul, jumlah halaman, penerbit, pengarang, tahun terbit,
 * kategori, jenis. Yang benar-benar berbeda cuma satu — `berkas`, berkas
 * digitalnya.
 *
 * Dua tabel untuk satu hal berarti setiap kolom baru harus ditambahkan
 * dua kali, dan setiap pencarian harus menggabungkan dua tabel. Yang
 * kedua itu yang berbahaya: pencarian yang lupa salah satu tabel akan
 * MENJAWAB DENGAN TENANG, hanya saja jawabannya tidak memuat separuh
 * koleksi perpustakaan — dan tidak ada yang tahu, karena hasilnya tetap
 * terlihat seperti hasil pencarian yang wajar.
 *
 * Karena itu satu tabel `collections` dengan kolom `medium`. Cetak punya
 * eksemplar fisik dan tidak punya berkas; ebook punya berkas dan tidak
 * punya eksemplar. Keduanya ditegakkan, bukan diserahkan pada kebiasaan
 * pengisi.
 *
 * EBOOK TANPA BERKAS ADALAH ENTRI KATALOG UNTUK SESUATU YANG TIDAK BISA
 * DIBUKA SIAPA PUN. Itu lebih buruk daripada tidak mengatalogkannya:
 * pemustaka menemukannya di hasil pencarian, mengiranya tersedia, lalu
 * tidak mendapat apa-apa. Karena itu CHECK, bukan imbauan.
 *
 * ISBN UNIK BILA DIISI. ISBN menandai satu EDISI secara tunggal di
 * seluruh dunia; dua entri katalog dengan ISBN sama berarti buku yang
 * sama dikatalogkan dua kali. Akibatnya bukan sekadar berantakan:
 * jumlah judul yang dilaporkan jadi lebih besar daripada kenyataannya,
 * dan pemustaka yang mencari satu buku menemukan dua entri yang
 * masing-masing menunjukkan sebagian eksemplarnya. Indeks unik parsial —
 * parsial karena banyak koleksi (skripsi, laporan penelitian, terbitan
 * internal) memang tidak punya ISBN, dan memaksakannya akan menolak
 * koleksi yang sah.
 *
 * PENGARANG JAMAK, KARENA MEMANG JAMAK. Khanza menyimpan satu
 * `kode_pengarang` per buku. Untuk perpustakaan rumah sakit itu keliru
 * pada kasus yang paling sering dipakai: buku teks kedokteran hampir
 * selalu ditulis banyak orang. Menyimpan hanya yang pertama membuat
 * pencarian atas nama pengarang kedua tidak menemukan apa pun — dan
 * pemustaka akan menyimpulkan bukunya tidak ada, bukan bahwa katalognya
 * tidak lengkap.
 *
 * Urutan pengarang ikut disimpan karena bukan sekadar urutan tampilan:
 * pengarang pertama yang dipakai pada sitasi, dan mengurutkannya menurut
 * abjad akan menghasilkan sitasi yang salah.
 *
 * PERAN PENGARANG (penulis/editor/penerjemah) SENGAJA BELUM DIMODELKAN.
 * Bedanya nyata pada sitasi, tapi kosakata perannya perlu ditetapkan
 * pustakawan RSP UI lebih dulu — dan menebaknya akan tercetak pada daftar
 * pustaka resmi. Ditulis di sini supaya tercatat sebagai keputusan, bukan
 * ditemukan orang lain sebagai kekurangan.
 */
return new class extends Migration
{
    private const S = 'library';

    private const MEDIUM = ['cetak', 'ebook'];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::S);

        // ------------------------------------------------------- master

        foreach ([
            'rooms' => 'Ruang/lokasi baca & simpan koleksi',
            'categories' => 'Kategori koleksi (klasifikasi subjek)',
            'collection_types' => 'Jenis koleksi (buku, jurnal, skripsi, laporan)',
            'publishers' => 'Penerbit',
        ] as $tabel => $keterangan) {
            Schema::create(self::S.'.'.$tabel, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('code', 20)->unique();
                $table->string('name', 150);
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
            });

            DB::statement('COMMENT ON TABLE '.self::S.'.'.$tabel." IS '".$keterangan."'");
        }

        Schema::create(self::S.'.authors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);

            // Nama untuk sitasi ditulis terpisah karena pembalikannya tidak
            // bisa ditebak dari nama lengkap: "Ahmad Sudirman Abbas" bisa jadi
            // "Abbas, A.S." atau "Sudirman Abbas, A." tergantung mana nama
            // keluarganya, dan menebak salah membuat daftar pustaka keliru.
            $table->string('citation_name', 150)->nullable()->comment('mis. "Harrison, T.R."');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // --------------------------------------------------- koleksi

        Schema::create(self::S.'.collections', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique()->comment('Nomor panggil / kode koleksi');
            $table->string('title', 250);

            $table->string('medium', 10)->default('cetak')->comment('cetak atau ebook');

            $table->unsignedBigInteger('publisher_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('collection_type_id')->nullable();

            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('edition', 40)->nullable();
            $table->string('isbn', 20)->nullable();

            // Hanya untuk ebook — ditegakkan CHECK di bawah.
            $table->string('file_path', 500)->nullable();

            $table->text('abstract')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category_id', 'is_active']);
            $table->index('collection_type_id');
        });

        DB::statement('ALTER TABLE '.self::S.".collections ADD CONSTRAINT collections_medium_check
            CHECK (medium IN ('".implode("','", self::MEDIUM)."'))");

        /*
         * Ebook wajib punya berkas; cetak tidak boleh punya. Entri ebook
         * tanpa berkas ditemukan pemustaka di hasil pencarian, dikira
         * tersedia, lalu tidak menghasilkan apa-apa.
         */
        DB::statement('ALTER TABLE '.self::S.".collections ADD CONSTRAINT collections_file_check
            CHECK (
                (medium = 'ebook' AND file_path IS NOT NULL AND btrim(file_path) <> '')
                OR (medium = 'cetak' AND file_path IS NULL)
            )");

        // Tahun terbit yang mustahil biasanya salah ketik, dan salah ketik
        // yang lolos akan muncul di grafik terbitan per tahun sebagai
        // lonjakan di tahun yang belum terjadi.
        DB::statement('ALTER TABLE '.self::S.'.collections ADD CONSTRAINT collections_year_check
            CHECK (publication_year IS NULL OR (publication_year BETWEEN 1400 AND 2200))');

        foreach ([
            'publisher_id' => 'publishers',
            'category_id' => 'categories',
            'collection_type_id' => 'collection_types',
        ] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.collections
                ADD CONSTRAINT collections_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        // Parsial: banyak koleksi sah memang tidak punya ISBN (skripsi,
        // laporan penelitian, terbitan internal).
        DB::statement('CREATE UNIQUE INDEX collections_isbn_unique
            ON '.self::S.".collections (isbn) WHERE isbn IS NOT NULL AND btrim(isbn) <> ''");

        Schema::create(self::S.'.collection_authors', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('collection_id')->constrained(self::S.'.collections')->cascadeOnDelete();
            $table->unsignedBigInteger('author_id');

            // Urutan sitasi, bukan urutan tampilan — lihat catatan kelas.
            $table->unsignedSmallInteger('position');

            $table->timestampsTz();

            $table->unique(['collection_id', 'author_id']);
            $table->unique(['collection_id', 'position']);
        });

        DB::statement('ALTER TABLE '.self::S.'.collection_authors
            ADD CONSTRAINT collection_authors_author_fk
            FOREIGN KEY (author_id) REFERENCES '.self::S.'.authors (id)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.collection_authors');
        Schema::dropIfExists(self::S.'.collections');
        Schema::dropIfExists(self::S.'.authors');
        Schema::dropIfExists(self::S.'.collection_types');
        Schema::dropIfExists(self::S.'.categories');
        Schema::dropIfExists(self::S.'.publishers');
        Schema::dropIfExists(self::S.'.rooms');

        DB::statement('DROP SCHEMA IF EXISTS '.self::S);
    }
};
