<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain I item E: integrasi akuntansi (pembayaran_akun_bayar 1-5,
 * pendapatan_per_akun, pendapatan_per_akun_closing).
 *
 * Dibangun di konteks finance, bukan billing (dikonfirmasi user), meski
 * katalog menandai ketujuh kodenya context=billing — isinya memetakan
 * uang ke bagan akun, dan chart_of_accounts/journal_entries memang tinggal
 * di sini. Membangunnya di billing berarti billing harus menyentuh bagan
 * akun milik finance.
 *
 * account_mappings adalah padanan tabel akun_bayar Khanza (nama_bayar ->
 * kd_rek), diperluas satu tingkat: selain cara bayar, jenis biaya pun
 * dipetakan, karena pendapatan_per_akun butuh tahu pendapatan dari
 * kamar/tindakan/obat masuk ke akun mana.
 *
 * CATATAN JUJUR: bagan akun yang ada baru empat akun contoh dari seeder,
 * bukan bagan akun RSP UI yang sebenarnya. Yang dibangun di sini
 * MEKANISMENYA (dikonfirmasi user); angkanya baru benar-benar berarti
 * setelah bagan akun disusun bersama bagian keuangan. Karena itu tidak
 * ada pemetaan bawaan yang diisi diam-diam — uang yang belum dipetakan
 * muncul sebagai "belum dipetakan", bukan dibuang atau ditebak.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        Schema::create(self::S . '.account_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('kind', 20)->comment('cara-bayar atau sumber-pendapatan');
            $table->string('key', 40)->comment('mis. tunai/qris untuk cara bayar, kamar/tindakan_ralan untuk sumber');
            $table->foreignId('account_id')->constrained(self::S . '.chart_of_accounts');

            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();

            $table->unique(['kind', 'key']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".account_mappings ADD CONSTRAINT account_mappings_kind_check
            CHECK (kind IN ('cara-bayar','sumber-pendapatan'))");

        /*
         * Penutupan periode. Begitu satu bulan ditutup, angkanya DIBEKUKAN
         * di period_closing_lines — bukan dihitung ulang setiap laporan
         * dibuka. Ini inti kode pendapatan_per_akun_closing: buku yang
         * sudah ditutup tidak boleh diam-diam berubah gara-gara ada
         * koreksi tagihan yang masuk belakangan. Kalau koreksinya memang
         * perlu, periode dibuka kembali secara sadar dan tercatat.
         */
        Schema::create(self::S . '.period_closings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            $table->timestampTz('closed_at');
            $table->unsignedBigInteger('closed_by')->comment('ID pengguna platform, referensi longgar');
            $table->text('note')->nullable();

            $table->timestampTz('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ' . self::S . '.period_closings ADD CONSTRAINT period_closings_month_check
            CHECK (period_month BETWEEN 1 AND 12)');

        // Satu periode hanya boleh punya satu penutupan yang masih berlaku;
        // yang sudah dibuka kembali boleh ditutup ulang. Pola unique parsial
        // yang sama dipakai sesi parkir dan piutang pasien.
        DB::statement('CREATE UNIQUE INDEX period_closings_aktif_unique ON ' . self::S . '.period_closings (period_year, period_month)
            WHERE reopened_at IS NULL');

        Schema::create(self::S . '.period_closing_lines', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('period_closing_id')->constrained(self::S . '.period_closings')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained(self::S . '.chart_of_accounts')
                ->comment('Null = belum dipetakan ke akun mana pun — sengaja ikut dibekukan, bukan disembunyikan');

            $table->string('kind', 20)->comment('cara-bayar atau sumber-pendapatan');
            $table->string('key', 40);
            $table->decimal('total', 16, 2);

            $table->timestampsTz();

            $table->index(['period_closing_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.period_closing_lines');
        Schema::dropIfExists(self::S . '.period_closings');
        Schema::dropIfExists(self::S . '.account_mappings');
    }
};
