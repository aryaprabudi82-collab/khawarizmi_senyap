<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bagan akun, jurnal manual & saldo awal (domain K item E) — ~10 kode.
 *
 * Menaungi akun_rekening, pengaturan_rekening, rekening_tahun,
 * jurnal_harian, posting_jurnal, buku_besar, saldo_akun_perbulan,
 * akun_aset_inventaris, dan pendapatan_per_carabayar.
 *
 * TEMUAN YANG MEMBUAT ITEM INI JADI KUNCI: sampai sekarang TIDAK ADA CARA
 * MENGELOLA BAGAN AKUN sama sekali. Hanya empat akun contoh dari seeder,
 * tanpa satu pun layar untuk menambahnya. Akibatnya seluruh kolom
 * account_id yang dibangun di item A (kategori kas), item B (hutang
 * vendor), dan item C (kategori piutang) secara harfiah tidak bisa diisi:
 * tidak ada akun yang bisa dipilih, dan tidak ada cara membuatnya. Semua
 * peringatan "belum dipetakan ke bagan akun" di layar-layar itu tidak
 * mungkin diselesaikan siapa pun sebelum ini ada.
 *
 * TIGA PERUBAHAN DI SINI:
 *
 * 1. Jenis akun diperluas dengan 'aset' dan 'modal'. Bagan akun yang
 *    hanya mengenal kas/piutang/utang/pendapatan/beban tidak bisa
 *    menampung aset tetap maupun ekuitas, padahal keduanya wajib ada di
 *    neraca rumah sakit.
 *
 * 2. reference_type dan reference_id pada jurnal dijadikan nullable.
 *    Keduanya NOT NULL sejak awal karena setiap jurnal lahir dari
 *    peristiwa lain (tagihan, deposit). Jurnal MANUAL tidak punya rujukan
 *    seperti itu, dan memaksanya mengisi rujukan palsu akan membuat
 *    setiap penelusuran balik menemukan tagihan yang tidak ada.
 *
 * 3. Saldo awal per tahun (rekening_tahun). Buku besar tanpa saldo awal
 *    hanya menampilkan mutasi, bukan posisi — dan posisi itulah yang
 *    dibaca orang. Saldo awal disimpan sebagai debit dan kredit terpisah,
 *    bukan satu angka bertanda, supaya arah normal tiap akun tetap
 *    terbaca apa adanya.
 */
return new class extends Migration
{
    private const S = 'finance';

    private const JENIS_LAMA = ['kas', 'piutang', 'pendapatan', 'beban', 'utang'];

    private const JENIS_BARU = ['kas', 'piutang', 'pendapatan', 'beban', 'utang', 'aset', 'modal'];

    public function up(): void
    {
        $this->gantiJenis(self::JENIS_BARU);

        DB::statement('ALTER TABLE ' . self::S . '.journal_entries ALTER COLUMN reference_type DROP NOT NULL');
        DB::statement('ALTER TABLE ' . self::S . '.journal_entries ALTER COLUMN reference_id DROP NOT NULL');

        Schema::create(self::S . '.account_opening_balances', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('account_id')->constrained(self::S . '.chart_of_accounts');
            $table->unsignedSmallInteger('fiscal_year');

            $table->decimal('opening_debit', 15, 2)->default(0);
            $table->decimal('opening_credit', 15, 2)->default(0);

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            // Satu saldo awal per akun per tahun. Kalau boleh ganda,
            // posisi awal buku besar menggelembung tanpa ada yang salah
            // secara kasat mata.
            $table->unique(['account_id', 'fiscal_year'], 'saldo_awal_akun_tahun_unique');
        });

        DB::statement('ALTER TABLE ' . self::S . '.account_opening_balances
            ADD CONSTRAINT saldo_awal_tidak_negatif
            CHECK (opening_debit >= 0 AND opening_credit >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.account_opening_balances');

        DB::table(self::S . '.chart_of_accounts')->whereIn('type', ['aset', 'modal'])->delete();
        $this->gantiJenis(self::JENIS_LAMA);

        DB::table(self::S . '.journal_entries')->whereNull('reference_type')->delete();
        DB::statement('ALTER TABLE ' . self::S . '.journal_entries ALTER COLUMN reference_type SET NOT NULL');
        DB::statement('ALTER TABLE ' . self::S . '.journal_entries ALTER COLUMN reference_id SET NOT NULL');
    }

    private function gantiJenis(array $jenis): void
    {
        // Nama batasan aslinya 'accounts_type_check', bukan turunan nama
        // tabelnya — menebak namanya membuat DROP ... IF EXISTS berhasil
        // tanpa membuang apa pun, dan dua batasan yang saling bertentangan
        // hidup berdampingan sampai ada yang mencoba menyimpan jenis baru.
        // Pelajaran yang sama seperti indeks berprefiks di domain I.
        foreach (['accounts_type_check', 'chart_of_accounts_type_check', 'finance_chart_of_accounts_type_check'] as $nama) {
            DB::statement('ALTER TABLE ' . self::S . '.chart_of_accounts DROP CONSTRAINT IF EXISTS ' . $nama);
        }

        DB::statement('ALTER TABLE ' . self::S . ".chart_of_accounts
            ADD CONSTRAINT chart_of_accounts_type_check
            CHECK (type IN ('" . implode("','", $jenis) . "'))");
    }
};
