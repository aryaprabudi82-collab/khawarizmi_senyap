<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eksemplar, keanggotaan, peminjaman & denda (domain Q item B).
 *
 * Enam kode: inventaris_perpustakaan, anggota_perpustakaan,
 * set_peminjaman_perpustakaan, peminjaman_perpustakaan,
 * denda_perpustakaan, bayar_denda_perpustakaan.
 *
 * DUA CACAT KHANZA YANG DIPERBAIKI DI SINI, DAN KEDUANYA BENTUKNYA SAMA
 * DENGAN YANG SUDAH DITEMUI PADA DOMAIN P: satu kolom dipakai menyimpan
 * dua hal yang berbeda.
 *
 * PERTAMA — `status_buku` MENCAMPUR KONDISI FISIK DENGAN STATUS
 * SIRKULASI. Enum Khanza berisi ('Ada','Rusak','Hilang','Dipinjam','-').
 * Empat nilai pertama itu bukan satu himpunan: rusak dan hilang menjawab
 * BAGAIMANA KEADAAN BENDANYA, dipinjam menjawab DI MANA BENDANYA SEKARANG.
 *
 * Akibatnya dua-duanya. Buku yang dipinjam lalu dikembalikan dalam
 * keadaan rusak tidak bisa dicatat sebagai keduanya — petugas harus
 * memilih, dan apa pun pilihannya ada satu fakta yang hilang. Lebih buruk
 * lagi, 'Dipinjam' adalah SUMBER KEDUA bagi fakta yang sudah dipegang
 * tabel peminjaman: begitu satu transaksi gagal di tengah jalan, kedua
 * sumber itu berbeda, dan tidak ada cara menentukan mana yang benar.
 *
 * Karena itu eksemplar cuma menyimpan KONDISI, dan "sedang dipinjam"
 * DIHITUNG dari ada-tidaknya peminjaman yang belum kembali.
 *
 * KEDUA — `tgl_kembali` DIPAKAI UNTUK DUA TANGGAL YANG BERBEDA. Satu
 * kolom, tapi pertanyaannya dua: kapan buku ini HARUS kembali, dan kapan
 * buku ini SUNGGUH kembali. Dendanya justru selisih keduanya.
 *
 * Dengan satu kolom, hanya salah satu yang bisa dijawab. Kalau diisi
 * jatuh tempo, tidak ada yang tahu kapan bukunya benar-benar dikembalikan
 * — jadi keterlambatan hanya bisa dihitung terhadap HARI INI, dan buku
 * yang dikembalikan terlambat tiga hari lalu akan terus bertambah
 * dendanya selama tidak ada yang menutup transaksinya. Kalau diisi
 * tanggal kembali, jatuh temponya hilang dan tidak ada pembanding sama
 * sekali. Khanza menambal ini dengan menyimpan `keterlambatan` sebagai
 * angka pada tabel denda — nilai turunan yang dibekukan, yang akan salah
 * begitu ada koreksi tanggal dan tidak ada yang tahu kapan itu terjadi.
 *
 * Di sini keduanya kolom terpisah, dan keterlambatan DIHITUNG.
 *
 * ATURAN PINJAM DIBEKUKAN SAAT MEMINJAM, DAN INI PENGECUALIAN YANG
 * DISENGAJA. Proyek ini menolak menyimpan nilai turunan; tapi jatuh tempo
 * bukan turunan aritmetik, ia JANJI YANG DIBUAT DI MEJA SIRKULASI. Kalau
 * jatuh tempo dihitung ulang dari pengaturan yang berlaku hari ini, maka
 * mengubah lama pinjam dari 7 jadi 14 hari akan menggeser jatuh tempo
 * seluruh pinjaman yang sedang berjalan — dan buku-buku yang kemarin
 * terlambat mendadak jadi tepat waktu, tanpa ada yang menyentuhnya.
 * Tarif denda harian ikut dibekukan dengan alasan yang sama.
 *
 * DAFTAR JENIS DENDA LAHIR KOSONG, TAPI DENDA KETERLAMBATAN TIDAK BUTUH
 * DAFTAR ITU. Besaran denda kerusakan dan kehilangan adalah diskresi RSP
 * UI — sama seperti alasan menolak anjuran medis dan pola klasifikasi
 * arsip pada domain P, dan menebaknya berarti menagih pemustaka dengan
 * angka yang tidak pernah disepakati siapa pun. Denda keterlambatan
 * berbeda: besarannya keluar dari tarif harian yang sudah dibekukan pada
 * pinjamannya, jadi ia tidak menunggu daftar apa pun.
 *
 * PEMBEBASAN DENDA WAJIB BERALASAN, PEMBAYARAN TIDAK. Bentuk
 * ketaksimetrisan yang sama dengan penolakan permintaan pasien pada
 * domain P: denda yang DIBAYAR meninggalkan bukti pada uangnya sendiri,
 * denda yang DIBEBASKAN tidak meninggalkan apa pun selain catatan ini —
 * dan pembebasan tanpa jejak adalah persis bentuk penyalahgunaan yang
 * paling mudah dilakukan orang dalam.
 */
return new class extends Migration
{
    private const S = 'library';

    private const JENIS_ANGGOTA = ['pasien', 'pegawai', 'umum'];

    /** Kondisi FISIK eksemplar. "Dipinjam" sengaja tidak ada di sini. */
    private const KONDISI = ['baik', 'rusak', 'hilang'];

    private const ASAL = ['beli', 'hibah', 'bantuan'];

    private const JENIS_DENDA = ['keterlambatan', 'kerusakan', 'kehilangan', 'lain'];

    public function up(): void
    {
        // Penomoran atomik — pola yang sama dengan konteks correspondence.
        Schema::create(self::S.'.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        // ------------------------------------------------- eksemplar

        Schema::create(self::S.'.items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('inventory_number', 30)->unique();
            $table->unsignedBigInteger('collection_id');

            $table->string('acquisition', 20)->default('beli');
            $table->date('acquired_at')->nullable();
            $table->decimal('price', 14, 2)->nullable();

            /*
             * KONDISI FISIK SAJA. "Sedang dipinjam" tidak disimpan di sini —
             * ia dihitung dari peminjaman yang belum kembali, supaya tidak
             * ada dua sumber untuk satu fakta.
             */
            $table->string('condition', 20)->default('baik');
            $table->text('condition_note')->nullable();

            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('shelf_no', 10)->nullable();
            $table->string('box_no', 10)->nullable();

            $table->timestampsTz();

            $table->index(['collection_id', 'condition']);
        });

        DB::statement('ALTER TABLE '.self::S.'.items
            ADD CONSTRAINT items_collection_fk
            FOREIGN KEY (collection_id) REFERENCES '.self::S.'.collections (id)');

        DB::statement('ALTER TABLE '.self::S.'.items
            ADD CONSTRAINT items_room_fk
            FOREIGN KEY (room_id) REFERENCES '.self::S.'.rooms (id)');

        DB::statement('ALTER TABLE '.self::S.".items ADD CONSTRAINT items_condition_check
            CHECK (condition IN ('".implode("','", self::KONDISI)."'))");

        DB::statement('ALTER TABLE '.self::S.".items ADD CONSTRAINT items_acquisition_check
            CHECK (acquisition IN ('".implode("','", self::ASAL)."'))");

        // --------------------------------------------------- anggota

        Schema::create(self::S.'.members', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('member_number', 20)->unique();
            $table->string('name', 150);
            $table->string('member_type', 20)->comment('pasien, pegawai, umum');

            /*
             * Rujukan longgar, TIDAK berupa foreign key ke identity.patients
             * atau hr.employees. Keanggotaan hidup lebih lama daripada
             * kepegawaian: mengikatnya akan membuat riwayat pinjam seorang
             * pensiunan lenyap bersama status pegawainya, padahal buku yang
             * belum ia kembalikan tetap harus bisa ditagih.
             */
            $table->string('person_ref', 30)->nullable()->comment('NIP/NIK/no. RM, untuk penelusuran manual');

            $table->date('birth_date')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable();

            $table->date('joined_at');
            $table->date('expires_at')->nullable()->comment('Masa berlaku keanggotaan; kosong berarti tidak berbatas');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['member_type', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.".members ADD CONSTRAINT members_type_check
            CHECK (member_type IN ('".implode("','", self::JENIS_ANGGOTA)."'))");

        DB::statement('ALTER TABLE '.self::S.".members ADD CONSTRAINT members_sex_check
            CHECK (sex IS NULL OR sex IN ('L','P'))");

        DB::statement('ALTER TABLE '.self::S.'.members ADD CONSTRAINT members_expiry_check
            CHECK (expires_at IS NULL OR expires_at >= joined_at)');

        // ----------------------------------------- pengaturan pinjam

        Schema::create(self::S.'.loan_policies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedSmallInteger('max_items')->comment('Batas eksemplar yang boleh dipegang sekaligus');
            $table->unsignedSmallInteger('loan_days');
            $table->decimal('daily_fine', 12, 2)->default(0);

            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
        });

        /*
         * Satu pengaturan aktif. Khanza menyimpannya sebagai satu baris tanpa
         * kunci sama sekali, jadi mengubah lama pinjam MENIMPA aturan lama dan
         * pertanyaan "aturan mana yang berlaku waktu itu" tidak punya jawaban.
         * Di sini yang lama dinonaktifkan, tidak dihapus.
         */
        DB::statement('CREATE UNIQUE INDEX loan_policy_aktif_unique
            ON '.self::S.'.loan_policies ((is_active)) WHERE is_active');

        // ------------------------------------------------ jenis denda

        Schema::create(self::S.'.fine_types', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // ------------------------------------------------ peminjaman

        Schema::create(self::S.'.loans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('loan_number', 24)->unique();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('item_id');

            $table->timestampTz('borrowed_at');

            // DUA TANGGAL YANG BERBEDA — lihat catatan panjang di kelas.
            $table->date('due_date');
            $table->timestampTz('returned_at')->nullable();

            /*
             * Aturan yang BERLAKU SAAT MEMINJAM, dibekukan. Bukan nilai
             * turunan: ini janji yang dibuat di meja sirkulasi, dan
             * menghitungnya ulang dari pengaturan hari ini akan menggeser
             * jatuh tempo seluruh pinjaman yang sedang berjalan.
             */
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->decimal('policy_daily_fine', 12, 2)->default(0);
            $table->unsignedSmallInteger('policy_loan_days');

            $table->string('returned_condition', 20)->nullable()->comment('Kondisi saat dikembalikan');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('issued_by_name', 150)->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 150)->nullable();

            $table->timestampsTz();

            $table->index(['member_id', 'returned_at']);
            $table->index('due_date');
        });

        foreach ([
            'member_id' => 'members',
            'item_id' => 'items',
            'policy_id' => 'loan_policies',
        ] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.loans
                ADD CONSTRAINT loans_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        DB::statement('ALTER TABLE '.self::S.".loans ADD CONSTRAINT loans_returned_condition_check
            CHECK (returned_condition IS NULL OR returned_condition IN ('".implode("','", self::KONDISI)."'))");

        // Kondisi kembali hanya berarti kalau bukunya memang sudah kembali.
        DB::statement('ALTER TABLE '.self::S.'.loans ADD CONSTRAINT loans_return_pair_check
            CHECK (returned_at IS NOT NULL OR returned_condition IS NULL)');

        DB::statement('ALTER TABLE '.self::S.'.loans ADD CONSTRAINT loans_return_after_borrow_check
            CHECK (returned_at IS NULL OR returned_at >= borrowed_at)');

        /*
         * SATU EKSEMPLAR HANYA BOLEH PUNYA SATU PINJAMAN TERBUKA. Tanpa ini,
         * dua orang bisa tercatat memegang buku fisik yang sama — dan yang
         * kedua akan ditagih atas buku yang tidak pernah ia terima.
         */
        DB::statement('CREATE UNIQUE INDEX loans_item_terbuka_unique
            ON '.self::S.'.loans (item_id) WHERE returned_at IS NULL');

        // ---------------------------------------------------- denda

        Schema::create(self::S.'.fines', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('fine_number', 24)->unique();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->unsignedBigInteger('fine_type_id')->nullable();

            $table->string('kind', 20);

            // Hanya untuk keterlambatan; DIHITUNG saat denda dibuat, lalu
            // dicatat sebagai dasar tagihan — bukan disimpan sebagai status
            // yang harus dijaga tetap mutakhir.
            $table->unsignedSmallInteger('days_late')->nullable();

            $table->decimal('amount', 14, 2);
            $table->text('note')->nullable();
            $table->timestampTz('charged_at');

            $table->timestampTz('paid_at')->nullable();
            $table->decimal('paid_amount', 14, 2)->nullable();

            $table->timestampTz('waived_at')->nullable();
            $table->text('waived_reason')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['member_id', 'paid_at']);
        });

        DB::statement('ALTER TABLE '.self::S.'.fines
            ADD CONSTRAINT fines_member_fk FOREIGN KEY (member_id) REFERENCES '.self::S.'.members (id)');
        DB::statement('ALTER TABLE '.self::S.'.fines
            ADD CONSTRAINT fines_loan_fk FOREIGN KEY (loan_id) REFERENCES '.self::S.'.loans (id)');
        DB::statement('ALTER TABLE '.self::S.'.fines
            ADD CONSTRAINT fines_type_fk FOREIGN KEY (fine_type_id) REFERENCES '.self::S.'.fine_types (id)');

        DB::statement('ALTER TABLE '.self::S.".fines ADD CONSTRAINT fines_kind_check
            CHECK (kind IN ('".implode("','", self::JENIS_DENDA)."'))");

        /*
         * Denda keterlambatan wajib menunjuk pinjamannya dan menyebut berapa
         * hari terlambat; denda lain tidak boleh punya hari keterlambatan.
         * Tanpa ini, "denda kerusakan 3 hari" bisa tersimpan dan tidak ada
         * yang bisa membacanya sebagai apa pun.
         */
        DB::statement('ALTER TABLE '.self::S.".fines ADD CONSTRAINT fines_late_check
            CHECK (
                (kind = 'keterlambatan' AND loan_id IS NOT NULL AND days_late IS NOT NULL AND days_late > 0)
                OR (kind <> 'keterlambatan' AND days_late IS NULL)
            )");

        // Pembebasan wajib beralasan.
        DB::statement('ALTER TABLE '.self::S.".fines ADD CONSTRAINT fines_waive_check
            CHECK (
                waived_at IS NULL
                OR (waived_reason IS NOT NULL AND btrim(waived_reason) <> '')
            )");

        // Satu denda tidak bisa sekaligus dibayar dan dibebaskan — kalau
        // boleh, jumlah penerimaan denda dan jumlah pembebasan akan
        // sama-sama memuatnya, dan keduanya jadi lebih besar dari kenyataan.
        DB::statement('ALTER TABLE '.self::S.'.fines ADD CONSTRAINT fines_settlement_check
            CHECK (NOT (paid_at IS NOT NULL AND waived_at IS NOT NULL))');

        DB::statement('ALTER TABLE '.self::S.'.fines ADD CONSTRAINT fines_paid_pair_check
            CHECK ((paid_at IS NULL AND paid_amount IS NULL) OR (paid_at IS NOT NULL AND paid_amount IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.fines');
        Schema::dropIfExists(self::S.'.loans');
        Schema::dropIfExists(self::S.'.fine_types');
        Schema::dropIfExists(self::S.'.loan_policies');
        Schema::dropIfExists(self::S.'.members');
        Schema::dropIfExists(self::S.'.items');
        Schema::dropIfExists(self::S.'.number_sequences');
    }
};
