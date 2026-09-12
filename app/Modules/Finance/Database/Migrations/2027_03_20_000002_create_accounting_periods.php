<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender periode akuntansi — Modul A butir 1.9.
 *
 * APA YANG SUDAH ADA. `finance.period_closings` mencatat PERISTIWA
 * penutupan: kapan ditutup, oleh siapa, kapan dibuka lagi, dan alasannya.
 * Itu jejak yang benar dan tetap dipertahankan.
 *
 * YANG BELUM ADA: KEADAAN periode itu sendiri. Sebuah periode hanya
 * mengenal dua keadaan — ada baris penutupan berarti tertutup, tidak ada
 * berarti terbuka. Dua keadaan tidak cukup untuk tutup buku yang
 * sesungguhnya, dan kekurangannya nyata:
 *
 *   TERBUKA      — transaksi harian berjalan normal.
 *
 *   SOFT-CLOSE   — transaksi operasional DITUTUP, tapi jurnal koreksi
 *                  masih boleh masuk. Inilah keadaan selama beberapa hari
 *                  di awal bulan ketika bagian keuangan merapikan angka
 *                  bulan lalu. Tanpa keadaan ini, pilihannya cuma dua:
 *                  biarkan terbuka (lalu transaksi baru terus masuk ke
 *                  bulan lalu) atau tutup sekarang (lalu koreksinya
 *                  tidak bisa dikerjakan).
 *
 *   TERTUTUP     — tidak ada yang boleh masuk; laporan sudah disusun.
 *                  Masih bisa dibuka kembali dengan alasan tertulis.
 *
 *   TERKUNCI     — laporan sudah dikirim ke luar (auditor, MWA, Kemenkeu).
 *                  TIDAK BISA dibuka lagi lewat aplikasi. Yang sudah
 *                  keluar rumah sakit tidak boleh berubah diam-diam, dan
 *                  membukanya harus jadi keputusan yang meninggalkan
 *                  jejak di luar sistem juga.
 *
 * PERIODE DIBUAT LEBIH DULU, TIDAK LAHIR SENDIRI SAAT ADA TRANSAKSI.
 * Periode yang lahir otomatis berarti transaksi bertanggal 2031 akan
 * membuat periodenya sendiri dan diterima tanpa pertanyaan — dan salah
 * ketik tahun adalah kesalahan yang paling sering terjadi di loket.
 */
return new class extends Migration
{
    private const S = 'finance';

    private const STATUS = ['terbuka', 'soft-close', 'tertutup', 'terkunci'];

    public function up(): void
    {
        Schema::create(self::S.'.accounting_periods', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedSmallInteger('period_year');
            $table->unsignedSmallInteger('period_month');

            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status', 15)->default('terbuka');

            /* Jejak perpindahan keadaan terakhir. */
            $table->timestampTz('status_changed_at')->nullable();
            $table->unsignedBigInteger('status_changed_by')->nullable()
                ->comment('ID pengguna platform, referensi longgar');
            $table->string('status_reason', 255)->nullable();

            $table->timestampsTz();

            $table->unique(['period_year', 'period_month']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE '.self::S.'.accounting_periods ADD CONSTRAINT periods_status_check
            CHECK (status IN (\''.implode("','", self::STATUS).'\'))');

        DB::statement('ALTER TABLE '.self::S.'.accounting_periods ADD CONSTRAINT periods_month_check
            CHECK (period_month BETWEEN 1 AND 12)');

        DB::statement('ALTER TABLE '.self::S.'.accounting_periods ADD CONSTRAINT periods_range_check
            CHECK (ends_on >= starts_on)');

        /*
         * PERPINDAHAN KE SELAIN TERBUKA WAJIB BERALASAN. Periode yang
         * dikunci tanpa keterangan tidak bisa dijelaskan kepada auditor
         * yang bertanya kenapa bulan itu tidak bisa disentuh lagi — dan
         * "tidak tahu" bukan jawaban.
         */
        DB::statement('ALTER TABLE '.self::S.'.accounting_periods ADD CONSTRAINT periods_alasan_check
            CHECK (status = \'terbuka\' OR (status_reason IS NOT NULL AND btrim(status_reason) <> \'\'))');

        /*
         * PENJAGA DI TINGKAT BASIS DATA, bukan hanya aplikasi.
         *
         * Jurnal yang masuk ke periode tertutup adalah bentuk kerusakan
         * yang paling sulit ketahuan: laporan yang sudah dikirim ke luar
         * berubah diam-diam, dan tidak ada satu pun galat. Aplikasi yang
         * memeriksanya tetap bisa dilewati seeder, perintah konsol, atau
         * perbaikan data lewat tinker.
         *
         * Soft-close SENGAJA MELOLOSKAN jurnal koreksi — itu memang
         * gunanya keadaan itu. Yang dibedakan adalah sumbernya: jurnal
         * bersumber transaksi operasional ditolak, jurnal manual (koreksi)
         * diterima.
         */
        DB::unprepared('
            CREATE OR REPLACE FUNCTION '.self::S.'.assert_period_open()
            RETURNS TRIGGER AS $$
            DECLARE
                v_status text;
                v_tahun  smallint;
                v_bulan  smallint;
            BEGIN
                v_tahun := extract(year from NEW.entry_date);
                v_bulan := extract(month from NEW.entry_date);

                SELECT status INTO v_status
                  FROM '.self::S.'.accounting_periods
                 WHERE period_year = v_tahun AND period_month = v_bulan;

                -- Periode yang belum didaftarkan DILOLOSKAN, dan itu
                -- disengaja: memaksa seluruh periode terdaftar lebih dulu
                -- akan mematikan sistem yang sudah berjalan hari ini demi
                -- kalender yang belum diisi siapa pun. Kelengkapannya
                -- dilaporkan siap:periksa, bukan ditegakkan trigger.
                IF v_status IS NULL THEN
                    RETURN NEW;
                END IF;

                IF v_status IN (\'tertutup\', \'terkunci\') THEN
                    RAISE EXCEPTION
                        \'Periode %-% berstatus %. Jurnal tidak bisa masuk ke periode yang sudah ditutup; buka kembali periodenya lebih dulu, dengan alasan tertulis.\',
                        v_tahun, lpad(v_bulan::text, 2, \'0\'), v_status
                        USING ERRCODE = \'23514\';
                END IF;

                IF v_status = \'soft-close\' AND NEW.reference_type IS NOT NULL THEN
                    RAISE EXCEPTION
                        \'Periode %-% sedang soft-close: hanya jurnal koreksi manual yang boleh masuk, sedangkan jurnal ini bersumber dari %.\',
                        v_tahun, lpad(v_bulan::text, 2, \'0\'), NEW.reference_type
                        USING ERRCODE = \'23514\';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::unprepared('
            CREATE TRIGGER journal_entries_period_open
            BEFORE INSERT OR UPDATE OF entry_date ON '.self::S.'.journal_entries
            FOR EACH ROW EXECUTE FUNCTION '.self::S.'.assert_period_open();
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_period_open ON '.self::S.'.journal_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::S.'.assert_period_open()');
        Schema::dropIfExists(self::S.'.accounting_periods');
    }
};
