<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran dokumen TANPA LOMPATAN (gapless).
 *
 * MENGAPA YANG SUDAH ADA BELUM CUKUP. Delapan konteks memakai pola
 * `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` pada tabel
 * `number_sequences` masing-masing. Pola itu AMAN terhadap konkurensi —
 * dua permintaan bersamaan tidak akan mendapat nomor yang sama — tapi ia
 * TIDAK gapless: kalau transaksi pemanggilnya rollback, nomor yang sudah
 * terambil hilang dan barisan nomornya berlubang.
 *
 * Untuk sebagian besar dokumen, lubang itu tidak apa-apa. Untuk EMPAT
 * jenis berikut, lubang adalah temuan audit:
 *
 *   - Faktur pajak (wajib gapless menurut ketentuan perpajakan)
 *   - Nomor jurnal
 *   - Nomor bukti kas
 *   - Nomor kuitansi
 *
 * CARA MENJAMINNYA: nomor yang terambil DICATAT, dan nomor yang batal
 * dipakai tidak hilang melainkan ditandai `dibatalkan` berikut alasannya.
 * Barisannya tetap utuh, dan yang hilang bisa dipertanggungjawabkan —
 * itulah yang sesungguhnya dituntut auditor, bukan ketiadaan pembatalan.
 *
 * MENGAPA BUKAN SEQUENCE POSTGRESQL. `nextval()` sengaja TIDAK
 * transaksional supaya cepat — persis sifat yang membuatnya berlubang
 * saat rollback. Yang dibutuhkan di sini justru kebalikannya.
 *
 * KONSEKUENSINYA HARUS DINYATAKAN: penomoran gapless MENGUNCI barisnya
 * sampai transaksi selesai, jadi dua penerbitan faktur pajak bersamaan
 * akan mengantre. Itu memang harga yang dibayar, dan wajar — faktur pajak
 * bukan transaksi berfrekuensi tinggi. JANGAN memakai mekanisme ini untuk
 * nomor antrean poli atau charge line.
 */
return new class extends Migration
{
    private const S = 'finance';

    /** Jenis dokumen yang barisan nomornya WAJIB utuh. */
    private const JENIS = ['faktur_pajak', 'jurnal', 'bukti_kas', 'kuitansi'];

    public function up(): void
    {
        Schema::create(self::S.'.document_number_series', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('document_type', 40);
            $table->string('period_key', 20)->comment('Mis. 2026 atau 202609 — nomor mengulang tiap periode');
            $table->string('prefix', 20);
            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestampsTz();

            $table->unique(['document_type', 'period_key']);
        });

        DB::statement('ALTER TABLE '.self::S.'.document_number_series ADD CONSTRAINT document_series_type_check
            CHECK (document_type IN (\''.implode("','", self::JENIS).'\'))');

        Schema::create(self::S.'.document_numbers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('series_id')->constrained(self::S.'.document_number_series');
            $table->unsignedBigInteger('sequence');
            $table->string('formatted', 60);

            /*
             * Status 'dibatalkan' inilah yang membuat barisan tetap utuh
             * tanpa memaksa nomor dipakai ulang. Nomor yang dipakai ulang
             * jauh lebih berbahaya daripada nomor yang batal: dua dokumen
             * berbeda dengan nomor sama tidak bisa dibedakan lagi.
             */
            $table->string('status', 20)->default('terpakai')->comment('terpakai, dibatalkan');
            $table->string('void_reason', 255)->nullable();

            $table->string('resource_type', 80)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->unsignedBigInteger('issued_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('issued_at');
            $table->timestampsTz();

            $table->unique(['series_id', 'sequence']);
            $table->unique('formatted');
            $table->index(['resource_type', 'resource_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.document_numbers ADD CONSTRAINT document_numbers_status_check
            CHECK (status IN (\'terpakai\',\'dibatalkan\'))');

        /*
         * Pembatalan WAJIB beralasan. Nomor yang batal tanpa keterangan
         * adalah persis lubang yang hendak dicegah mekanisme ini —
         * auditor menanyakan ke mana perginya nomor itu, dan "tidak tahu"
         * bukan jawaban.
         */
        DB::statement('ALTER TABLE '.self::S.'.document_numbers ADD CONSTRAINT document_numbers_void_reason_check
            CHECK (status <> \'dibatalkan\' OR (void_reason IS NOT NULL AND btrim(void_reason) <> \'\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.document_numbers');
        Schema::dropIfExists(self::S.'.document_number_series');
    }
};
