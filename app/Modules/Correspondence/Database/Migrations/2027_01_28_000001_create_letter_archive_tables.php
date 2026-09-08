<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arsip & penomoran surat (domain P item D).
 *
 * Sembilan kode: surat_ruang, surat_almari, surat_rak, surat_map,
 * surat_indeks, surat_klasifikasi, surat_sifat, surat_status, surat_balas.
 *
 * SATU KOLOM YANG SELAMA INI MENYIMPAN DUA HAL YANG BERBEDA.
 *
 * `incoming_letters.classification` dan `outgoing_letters.classification`
 * dibatasi CHECK ke ('biasa','penting','rahasia','segera'). Empat nilai
 * itu bukan satu himpunan: 'rahasia' menjawab SEBERAPA TERBATAS surat ini
 * boleh dibaca, 'segera' menjawab SEBERAPA CEPAT ia harus ditangani.
 * Tata naskah dinas memang mengenal keduanya sebagai dua sumbu terpisah —
 * sifat (biasa, terbatas, rahasia, sangat rahasia) dan derajat (biasa,
 * segera, amat segera).
 *
 * Akibat menggabungkannya nyata: surat rahasia yang juga mendesak hanya
 * bisa dicatat sebagai salah satunya. Kalau petugas memilih 'rahasia',
 * kecepatannya hilang dan surat itu mengantre seperti surat biasa; kalau
 * memilih 'segera', keterbatasan aksesnya hilang dan ia diperlakukan
 * seperti surat yang boleh dibaca siapa saja. Tidak ada pilihan yang
 * benar, dan itu tanda kolomnya yang salah, bukan pengisinya.
 *
 * Karena itu dipecah jadi `security` dan `urgency`. Nilai lama
 * dipindahkan apa adanya; 'penting' — yang tidak ada di kedua daftar
 * resmi — dipetakan ke derajat 'segera', karena di daftar lama tidak ada
 * nilai lain yang menyatakan kecepatan, jadi itulah maksudnya ketika
 * dipilih.
 *
 * DAN 'KLASIFIKASI' YANG SEBENARNYA TIDAK PERNAH ADA. Yang selama ini
 * bernama `classification` isinya sifat surat. Klasifikasi dalam arti
 * kearsipan adalah KODE PERIHAL — pola klasifikasi arsip yang disusun
 * tiap instansi mengikuti pedoman ANRI, dan justru itulah yang dipakai
 * untuk menemukan kembali surat bertahun kemudian. Sekarang ia punya
 * tabelnya sendiri, dua tingkat (klasifikasi dan sub-klasifikasi).
 *
 * LOKASI FISIK JADI SATU POHON, BUKAN EMPAT DAFTAR DATAR. Khanza punya
 * surat_ruang, surat_lemari, surat_rak, dan surat_map sebagai empat tabel
 * terpisah berisi kode dan nama, lalu surat_masuk menyimpan keempat
 * kodenya berdampingan. Susunan itu tidak bisa menyatakan bahwa rak 3
 * berada DI DALAM almari B DI DALAM ruang arsip — dan karena tidak bisa,
 * ia juga tidak bisa menolak kombinasi yang mustahil: rak yang ada di
 * ruang lain, map yang tidak ada di rak mana pun. Satu pohon berjenjang
 * menolak kombinasi itu dengan sendirinya.
 *
 * DISPOSISI JAMAK, BUKAN SATU KOLOM. `forwarded_to` cuma bisa menyimpan
 * satu tujuan, padahal satu surat masuk lazim didisposisikan berturut-
 * turut: direktur ke kepala bidang, kepala bidang ke pelaksana. Yang
 * hilang bukan cuma daftarnya, tapi juga TENGGATNYA — disposisi tanpa
 * tanggal selesai tidak bisa dilaporkan sebagai terlambat, dan disposisi
 * yang tidak bisa terlambat tidak pernah ditagih siapa pun.
 *
 * BALASAN HARUS MENUNJUK SURATNYA. Surat yang ditandai "sudah dibalas"
 * tanpa menunjuk surat balasannya adalah klaim yang tidak bisa diperiksa
 * — dan pada saat audit, klaim yang tidak bisa diperiksa dianggap tidak
 * benar. Karena itu status balasan menyimpan id surat keluar yang
 * menjawabnya.
 *
 * NOMOR SURAT KELUAR BERURUT PER KLASIFIKASI PER TAHUN, bukan satu
 * hitungan tunggal. Nomor surat dinas dibaca sebagai alamat arsip:
 * urutan, kode perihal, satuan kerja, bulan, tahun. Hitungan tunggal
 * menghasilkan nomor yang tidak memberi tahu apa pun tentang isinya, dan
 * membuat penemuan kembali bergantung pada mesin pencari alih-alih pada
 * nomornya sendiri.
 *
 * NOMOR TIDAK PERNAH DIPAKAI ULANG. Surat yang batal meninggalkan lubang
 * pada urutan, dan lubang itu adalah informasi: sebuah nomor pernah
 * diterbitkan. Memakainya ulang membuat dua dokumen bernomor sama, dan
 * itu kerusakan yang tidak bisa diperbaiki belakangan.
 */
return new class extends Migration
{
    private const S = 'correspondence';

    /** Sifat surat: seberapa terbatas ia boleh dibaca. */
    private const SIFAT = ['biasa', 'terbatas', 'rahasia', 'sangat-rahasia'];

    /** Derajat surat: seberapa cepat ia harus ditangani. */
    private const DERAJAT = ['biasa', 'segera', 'amat-segera'];

    private const JENJANG_LOKASI = ['ruang', 'almari', 'rak', 'map'];

    private const STATUS_BALASAN = ['tidak-perlu', 'menunggu', 'sudah-dibalas'];

    public function up(): void
    {
        // ------------------------------------------------- lokasi arsip

        Schema::create(self::S.'.letter_locations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('level', 10)->comment('ruang, almari, rak, map');

            $table->string('code', 20);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['parent_id', 'code']);
            $table->index(['level', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.'.letter_locations
            ADD CONSTRAINT letter_locations_parent_fk
            FOREIGN KEY (parent_id) REFERENCES '.self::S.'.letter_locations (id)');

        DB::statement('ALTER TABLE '.self::S.".letter_locations ADD CONSTRAINT letter_locations_level_check
            CHECK (level IN ('".implode("','", self::JENJANG_LOKASI)."'))");

        /*
         * Ruang adalah akar, sisanya harus punya induk. Ini menahan setengah
         * dari kombinasi mustahil; jenjang induknya sendiri ditegakkan
         * service, karena CHECK tidak bisa membaca baris lain.
         */
        DB::statement('ALTER TABLE '.self::S.".letter_locations ADD CONSTRAINT letter_locations_root_check
            CHECK ((level = 'ruang' AND parent_id IS NULL) OR (level <> 'ruang' AND parent_id IS NOT NULL))");

        // --------------------------------------------- klasifikasi arsip

        Schema::create(self::S.'.letter_classifications', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('parent_id')->nullable()->comment('Induk untuk sub-klasifikasi');
            $table->string('code', 20)->unique()->comment('Kode perihal, mis. KP.01.01');
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.'.letter_classifications
            ADD CONSTRAINT letter_classifications_parent_fk
            FOREIGN KEY (parent_id) REFERENCES '.self::S.'.letter_classifications (id)');

        // ------------------------------------------------ indeks temu balik

        Schema::create(self::S.'.letter_index_terms', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // ------------------------------------------- urutan nomor surat

        Schema::create(self::S.'.letter_number_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('classification_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();

            $table->primary(['classification_id', 'year']);
        });

        // ------------------------------------------------------ disposisi

        Schema::create(self::S.'.letter_dispositions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('incoming_letter_id')
                ->constrained(self::S.'.incoming_letters')->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('to_name', 150)->comment('Unit atau pejabat tujuan disposisi');
            $table->text('instruction')->comment('Isi disposisi: apa yang diminta dikerjakan');
            $table->unsignedBigInteger('index_term_id')->nullable();

            // Tanpa tenggat, disposisi tidak bisa dilaporkan terlambat — dan
            // yang tidak bisa terlambat tidak pernah ditagih siapa pun.
            $table->date('due_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_note')->nullable();

            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->string('disposed_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->unique(['incoming_letter_id', 'sequence']);
            $table->index('due_date');
        });

        DB::statement('ALTER TABLE '.self::S.'.letter_dispositions
            ADD CONSTRAINT letter_dispositions_index_fk
            FOREIGN KEY (index_term_id) REFERENCES '.self::S.'.letter_index_terms (id)');

        // ------------------------------------------- perluasan surat masuk

        Schema::table(self::S.'.incoming_letters', function (Blueprint $table) {
            $table->string('security', 20)->default('biasa');
            $table->string('urgency', 20)->default('biasa');

            $table->unsignedBigInteger('classification_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable()->comment('Map/rak tempat aslinya disimpan');

            $table->date('reply_due_date')->nullable();
            $table->string('reply_status', 20)->default('tidak-perlu');
            $table->unsignedBigInteger('replied_by_letter_id')->nullable()
                ->comment('Surat keluar yang menjawabnya — klaim "sudah dibalas" harus bisa ditunjuk');

            $table->string('attachment_note', 300)->nullable()->comment('Lampiran');
            $table->string('copy_to', 300)->nullable()->comment('Tembusan');
        });

        Schema::table(self::S.'.outgoing_letters', function (Blueprint $table) {
            $table->string('security', 20)->default('biasa');
            $table->string('urgency', 20)->default('biasa');

            $table->unsignedBigInteger('classification_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            $table->unsignedBigInteger('replies_to_letter_id')->nullable()
                ->comment('Surat masuk yang dijawabnya');

            $table->string('attachment_note', 300)->nullable();
            $table->string('copy_to', 300)->nullable();
        });

        // Nilai lama dipindahkan apa adanya; 'penting' jadi derajat 'segera'
        // karena di daftar lama tidak ada nilai lain yang menyatakan
        // kecepatan.
        foreach (['incoming_letters', 'outgoing_letters'] as $tabel) {
            DB::statement('UPDATE '.self::S.'.'.$tabel."
                SET security = CASE WHEN classification = 'rahasia' THEN 'rahasia' ELSE 'biasa' END,
                    urgency  = CASE WHEN classification IN ('segera','penting') THEN 'segera' ELSE 'biasa' END");

            DB::statement('ALTER TABLE '.self::S.'.'.$tabel." ADD CONSTRAINT {$tabel}_security_check
                CHECK (security IN ('".implode("','", self::SIFAT)."'))");
            DB::statement('ALTER TABLE '.self::S.'.'.$tabel." ADD CONSTRAINT {$tabel}_urgency_check
                CHECK (urgency IN ('".implode("','", self::DERAJAT)."'))");

            DB::statement('ALTER TABLE '.self::S.'.'.$tabel." ADD CONSTRAINT {$tabel}_classification_fk
                FOREIGN KEY (classification_id) REFERENCES ".self::S.'.letter_classifications (id)');
            DB::statement('ALTER TABLE '.self::S.'.'.$tabel." ADD CONSTRAINT {$tabel}_location_fk
                FOREIGN KEY (location_id) REFERENCES ".self::S.'.letter_locations (id)');

            /*
             * Kolom lama DITINGGALKAN, bukan dihapus. Menghapusnya membuang
             * satu-satunya bukti apa yang sebenarnya dipilih petugas sebelum
             * pemisahan ini — dan pemetaan 'penting' ke 'segera' adalah
             * tafsir, bukan fakta. Kalau tafsirnya keliru, kolom ini yang
             * memungkinkan diperbaiki.
             */
            DB::statement('COMMENT ON COLUMN '.self::S.'.'.$tabel.".classification IS
                'USANG sejak domain P item D — isinya sifat surat, sudah dipindah ke security/urgency. Disimpan sebagai bukti pilihan asli petugas karena pemetaan penting->segera adalah tafsir.'");
        }

        DB::statement('ALTER TABLE '.self::S.".incoming_letters ADD CONSTRAINT incoming_letters_reply_status_check
            CHECK (reply_status IN ('".implode("','", self::STATUS_BALASAN)."'))");

        /*
         * "Sudah dibalas" wajib menunjuk surat balasannya; yang belum
         * dibalas tidak boleh menunjuk apa pun. Klaim yang tidak bisa
         * diperiksa, pada saat audit, dianggap tidak benar.
         */
        DB::statement('ALTER TABLE '.self::S.".incoming_letters ADD CONSTRAINT incoming_letters_reply_link_check
            CHECK (
                (reply_status = 'sudah-dibalas' AND replied_by_letter_id IS NOT NULL)
                OR (reply_status <> 'sudah-dibalas' AND replied_by_letter_id IS NULL)
            )");

        // Tenggat balas hanya berarti kalau balasannya memang ditunggu.
        DB::statement('ALTER TABLE '.self::S.".incoming_letters ADD CONSTRAINT incoming_letters_reply_due_check
            CHECK (reply_status <> 'tidak-perlu' OR reply_due_date IS NULL)");

        DB::statement('ALTER TABLE '.self::S.'.incoming_letters
            ADD CONSTRAINT incoming_letters_replied_fk
            FOREIGN KEY (replied_by_letter_id) REFERENCES '.self::S.'.outgoing_letters (id)');

        DB::statement('ALTER TABLE '.self::S.'.outgoing_letters
            ADD CONSTRAINT outgoing_letters_replies_fk
            FOREIGN KEY (replies_to_letter_id) REFERENCES '.self::S.'.incoming_letters (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.self::S.'.outgoing_letters DROP CONSTRAINT outgoing_letters_replies_fk');
        DB::statement('ALTER TABLE '.self::S.'.incoming_letters DROP CONSTRAINT incoming_letters_replied_fk');
        DB::statement('ALTER TABLE '.self::S.'.incoming_letters DROP CONSTRAINT incoming_letters_reply_due_check');
        DB::statement('ALTER TABLE '.self::S.'.incoming_letters DROP CONSTRAINT incoming_letters_reply_link_check');
        DB::statement('ALTER TABLE '.self::S.'.incoming_letters DROP CONSTRAINT incoming_letters_reply_status_check');

        foreach (['incoming_letters', 'outgoing_letters'] as $tabel) {
            foreach (['security_check', 'urgency_check', 'classification_fk', 'location_fk'] as $akhiran) {
                DB::statement('ALTER TABLE '.self::S.'.'.$tabel." DROP CONSTRAINT {$tabel}_{$akhiran}");
            }
        }

        Schema::table(self::S.'.outgoing_letters', function (Blueprint $table) {
            $table->dropColumn([
                'security', 'urgency', 'classification_id', 'location_id',
                'replies_to_letter_id', 'attachment_note', 'copy_to',
            ]);
        });

        Schema::table(self::S.'.incoming_letters', function (Blueprint $table) {
            $table->dropColumn([
                'security', 'urgency', 'classification_id', 'location_id',
                'reply_due_date', 'reply_status', 'replied_by_letter_id',
                'attachment_note', 'copy_to',
            ]);
        });

        Schema::dropIfExists(self::S.'.letter_dispositions');
        Schema::dropIfExists(self::S.'.letter_number_sequences');
        Schema::dropIfExists(self::S.'.letter_index_terms');
        Schema::dropIfExists(self::S.'.letter_classifications');
        Schema::dropIfExists(self::S.'.letter_locations');
    }
};
