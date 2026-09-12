<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COA hierarkis + dimensi pada baris jurnal — Modul A, Wave 1 butir 1.4.
 *
 * MENGAPA MIGRASI INI ADA DI MODUL FINANCE, BUKAN KEUANGAN/MASTERDATA.
 *
 * Isinya memang pekerjaan Modul A, tapi yang disentuhnya adalah skema
 * `finance` — dan uji batas konteks melarang sebuah modul menyentuh skema
 * milik konteks lain. Larangan itu benar, dan melonggarkannya demi
 * kerapian direktori berarti membuang penjagaan yang sudah menangkap
 * kesalahan nyata berkali-kali.
 *
 * Pemindahan COA ke `keuangan_master` adalah pemindahan tersendiri yang
 * tercatat di MIGRATION-MAP.md dan dikerjakan pada Wave 4, bersama
 * LedgerService dan PostingService — sekaligus, supaya tidak ada masa
 * ketika separuh buku besar ada di satu skema dan separuhnya di skema lain.
 *
 * DUA PERUBAHAN, DAN KEDUANYA MENGIKUTI KEPUTUSAN YANG SUDAH DITULIS.
 *
 * 1. HIERARKI PADA AKUN (parent_id + klasifikasi PSAK).
 *
 *    RSP UI berstatus PTN-BH, jadi laporannya mengikuti SAK umum (PSAK),
 *    bukan SAP/PSAP — lihat jawaban Q3. Bentuk laporannya menuntut
 *    klasifikasi yang belum ada di sini: aset LANCAR vs TIDAK LANCAR,
 *    liabilitas JANGKA PENDEK vs JANGKA PANJANG. Tanpa itu, Laporan
 *    Posisi Keuangan tidak bisa disusun dalam bentuk yang dikenali
 *    auditor — ia cuma jadi daftar saldo yang dikelompokkan seadanya.
 *
 *    Hierarki dibutuhkan supaya akun induk MENJUMLAHKAN anaknya. Tanpa
 *    parent_id, setiap penjumlahan sub-total harus dikodekan di laporan,
 *    dan menambah satu akun berarti menyunting laporannya.
 *
 * 2. DIMENSI PADA BARIS JURNAL, BUKAN PADA KODE AKUN (keputusan KA-4).
 *
 *    Cara lain yang lazim adalah memasukkan dimensi ke dalam nomor akun:
 *    `4-1000-RJ-PELAYANAN-DR001`. Itu terlihat rapi dan SELALU berakhir
 *    sama — jumlah akun meledak jadi puluhan ribu, menambah satu dimensi
 *    berarti menomori ulang seluruh bagan akun, dan laporan per dimensi
 *    jadi latihan mengurai string.
 *
 *    Dimensi sebagai kolom membuat SATU akun pendapatan bisa dilaporkan
 *    per unit, per dokter, per program, dan per sumber dana SEKALIGUS,
 *    tanpa satu pun akun tambahan.
 *
 * DIMENSI `program` ADALAH YANG PALING MENENTUKAN BAGI PTN-BH: pelayanan,
 * pendidikan, penelitian. Inilah yang membuat dana pendidikan bisa
 * dilaporkan terpisah tanpa menggandakan bagan akun — dan pemisahan itu
 * bukan kerapian, melainkan syarat pertanggungjawaban dana.
 *
 * KOLOM LAMA `type` DIPERTAHANKAN. Ia dipakai PostingService dan
 * DepositService sebagai PENUNJUK akun (`account(TYPE_KAS)` = "carikan
 * akun kas"), dan menghapusnya akan mematikan seluruh penjurnalan
 * otomatis yang sudah berjalan. Klasifikasi PSAK ditambahkan sebagai
 * lapisan baru di sebelahnya, bukan pengganti.
 */
return new class extends Migration
{
    private const S = 'finance';

    /** Klasifikasi laporan menurut PSAK — menentukan letak akun di Laporan Posisi Keuangan. */
    private const KLASIFIKASI = [
        'aset-lancar',
        'aset-tidak-lancar',
        'liabilitas-jangka-pendek',
        'liabilitas-jangka-panjang',
        'ekuitas',
        'pendapatan',
        'beban',
    ];

    /** Dimensi program PTN-BH — pemisah dana. */
    private const PROGRAM = ['pelayanan', 'pendidikan', 'penelitian'];

    public function up(): void
    {
        Schema::table(self::S.'.chart_of_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('id')
                ->comment('Akun induk; akun induk menjumlahkan anaknya');

            $table->string('klasifikasi', 30)->nullable()->after('type')
                ->comment('Klasifikasi PSAK untuk Laporan Posisi Keuangan');

            /*
             * Akun POSTABLE vs akun RINGKASAN. Akun induk yang hanya
             * menjumlahkan anaknya TIDAK BOLEH dijurnalkan langsung —
             * kalau boleh, saldo induk jadi campuran antara jumlah anaknya
             * dan jurnal langsungnya sendiri, dan tidak ada cara memisahkan
             * keduanya lagi.
             */
            $table->boolean('is_postable')->default(true)->after('is_active')
                ->comment('Akun ringkasan (induk) tidak boleh dijurnalkan langsung');

            $table->foreign('parent_id')->references('id')->on(self::S.'.chart_of_accounts');
            $table->index('klasifikasi');
        });

        DB::statement('ALTER TABLE '.self::S.'.chart_of_accounts ADD CONSTRAINT accounts_klasifikasi_check
            CHECK (klasifikasi IS NULL OR klasifikasi IN (\''.implode("','", self::KLASIFIKASI).'\'))');

        /* Akun tidak boleh jadi induk dirinya sendiri. */
        DB::statement('ALTER TABLE '.self::S.'.chart_of_accounts ADD CONSTRAINT accounts_parent_bukan_diri_sendiri
            CHECK (parent_id IS NULL OR parent_id <> id)');

        // ------------------------------------------------ dimensi pada jurnal

        Schema::table(self::S.'.journal_lines', function (Blueprint $table) {
            /*
             * ENAM DIMENSI. Seluruhnya nullable, dan itu disengaja: jurnal
             * penutup, jurnal koreksi, dan jurnal saldo awal memang tidak
             * punya unit maupun DPJP. Mewajibkannya akan memaksa orang
             * mengisi nilai palsu — dan nilai palsu pada dimensi jauh lebih
             * buruk daripada dimensi kosong, karena laporan per unit jadi
             * memuat angka yang tidak pernah terjadi di unit itu.
             */
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('account_id');
            $table->unsignedBigInteger('unit_id')->nullable()->after('cost_center_id')
                ->comment('ID unit organization, referensi longgar lintas konteks');
            $table->string('sumber_dana', 30)->nullable()->after('unit_id');
            $table->string('proyek', 40)->nullable()->after('sumber_dana');
            $table->unsignedBigInteger('praktisi_id')->nullable()->after('proyek')
                ->comment('DPJP/pelaksana, untuk profitabilitas per dokter');
            $table->string('program', 20)->nullable()->after('praktisi_id')
                ->comment('pelayanan/pendidikan/penelitian — dimensi PTN-BH');

            /*
             * Indeks per dimensi yang benar-benar dipakai laporan. Bukan
             * seluruh enam: indeks yang tidak pernah dipakai tetap menambah
             * biaya setiap penulisan, dan journal_lines adalah tabel yang
             * paling sering ditulis di seluruh domain keuangan.
             */
            $table->index(['program', 'account_id']);
            $table->index(['unit_id', 'account_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.journal_lines ADD CONSTRAINT journal_lines_program_check
            CHECK (program IS NULL OR program IN (\''.implode("','", self::PROGRAM).'\'))');

        /*
         * SATU BARIS JURNAL: DEBIT ATAU KREDIT, TIDAK KEDUANYA.
         *
         * Baris yang mengisi debit DAN kredit sekaligus secara aritmetika
         * masih bisa membuat jurnalnya balance, tapi ia menghancurkan
         * setiap laporan yang menjumlahkan salah satu kolomnya — dan tidak
         * ada satu pun tanda bahwa ada yang salah. Ditambahkan sekarang
         * karena saat ini seluruh jurnal masih memenuhinya.
         */
        DB::statement('ALTER TABLE '.self::S.'.journal_lines ADD CONSTRAINT journal_lines_satu_sisi
            CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.self::S.'.journal_lines DROP CONSTRAINT IF EXISTS journal_lines_satu_sisi');
        DB::statement('ALTER TABLE '.self::S.'.journal_lines DROP CONSTRAINT IF EXISTS journal_lines_program_check');

        Schema::table(self::S.'.journal_lines', function (Blueprint $table) {
            $table->dropIndex(['program', 'account_id']);
            $table->dropIndex(['unit_id', 'account_id']);
            $table->dropColumn(['cost_center_id', 'unit_id', 'sumber_dana', 'proyek', 'praktisi_id', 'program']);
        });

        DB::statement('ALTER TABLE '.self::S.'.chart_of_accounts DROP CONSTRAINT IF EXISTS accounts_parent_bukan_diri_sendiri');
        DB::statement('ALTER TABLE '.self::S.'.chart_of_accounts DROP CONSTRAINT IF EXISTS accounts_klasifikasi_check');

        Schema::table(self::S.'.chart_of_accounts', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'klasifikasi', 'is_postable']);
        });
    }
};
