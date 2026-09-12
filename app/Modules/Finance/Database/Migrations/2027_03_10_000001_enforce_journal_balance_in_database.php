<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jurnal WAJIB balance — ditegakkan basis data, bukan hanya aplikasi.
 *
 * TEMUAN DISCOVERY TAHAP 0. `finance.journal_entries` hanya punya UNIQUE dan
 * NOT NULL. Keseimbangan debit-kredit dijaga `LedgerService::postManual()`
 * di PHP, dan itu berarti: satu jalur tulis baru yang lupa memeriksanya —
 * posting engine, seeder, perintah konsol, perbaikan data lewat tinker —
 * akan merusak seluruh neraca di atasnya TANPA SATU PUN PENAHAN.
 *
 * Kerusakannya juga tidak langsung terlihat. Neraca tetap tersusun rapi;
 * yang muncul cuma selisih yang tidak bisa dijelaskan siapa pun, berbulan-
 * bulan kemudian saat ada yang menutup buku.
 *
 * MENGAPA CONSTRAINT TRIGGER, BUKAN CHECK BIASA.
 * CHECK hanya bisa melihat satu baris. Keseimbangan jurnal adalah sifat
 * SEKUMPULAN baris, dan saat baris pertama disisipkan jurnalnya memang
 * belum balance — itu keadaan yang sah di tengah transaksi. Maka
 * pemeriksaannya harus DITANGGUHKAN sampai COMMIT: CONSTRAINT TRIGGER
 * ... DEFERRABLE INITIALLY DEFERRED.
 *
 * MENGAPA SEKARANG, BUKAN NANTI.
 * Saat migrasi ini ditulis, basis data berisi 10 jurnal dan SELURUHNYA
 * balance — jadi penambahan constraint aman. Menundanya sampai ada ribuan
 * jurnal membuat migrasi yang sama berisiko gagal di tengah, dan
 * memperbaiki jurnal lama yang miring jauh lebih mahal daripada mencegah
 * yang baru.
 *
 * JURNAL KOSONG JUGA DITOLAK. Jurnal tanpa satu pun baris secara teknis
 * "balance" (0 = 0), tapi ia dokumen yang tidak menyatakan apa-apa —
 * biasanya sisa transaksi yang gagal di tengah. Membiarkannya membuat
 * daftar jurnal berisi entri hampa yang tidak ada yang berani menghapus.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        /*
         * Pemeriksaannya satu, pemanggilnya dua — dan itu bukan pilihan
         * gaya. PL/pgSQL menolak `NEW.kolom_yang_tidak_ada` pada saat
         * JALAN, bahkan di dalam COALESCE, karena NEW bertipe baris tabel
         * pemicunya. Satu fungsi yang menyebut `NEW.journal_entry_id`
         * akan meledak begitu dipasang pada journal_entries yang tidak
         * punya kolom itu — persis yang terjadi pada percobaan pertama,
         * dan galatnya menyamar sebagai "constraint bekerja" padahal
         * sebabnya sama sekali lain.
         */
        DB::unprepared('
            CREATE OR REPLACE FUNCTION '.self::S.'.assert_entry_balanced(p_entry_id bigint)
            RETURNS void AS $$
            DECLARE
                v_debit  numeric;
                v_credit numeric;
                v_lines  integer;
                v_number text;
            BEGIN
                -- Header yang sudah tidak ada berarti ikut terhapus dalam
                -- transaksi yang sama; tidak ada yang perlu diperiksa.
                SELECT entry_number INTO v_number
                  FROM '.self::S.'.journal_entries WHERE id = p_entry_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT COALESCE(sum(debit), 0), COALESCE(sum(credit), 0), count(*)
                  INTO v_debit, v_credit, v_lines
                  FROM '.self::S.'.journal_lines
                 WHERE journal_entry_id = p_entry_id;

                IF v_lines = 0 THEN
                    RAISE EXCEPTION
                        \'Jurnal % tidak punya satu pun baris. Jurnal tanpa baris tidak menyatakan apa-apa.\',
                        COALESCE(v_number, p_entry_id::text)
                        USING ERRCODE = \'23514\';
                END IF;

                IF v_debit <> v_credit THEN
                    RAISE EXCEPTION
                        \'Jurnal % tidak seimbang: debit % vs kredit %, selisih %. Koreksi lewat entri pembalik, bukan dengan membiarkan selisih.\',
                        COALESCE(v_number, p_entry_id::text), v_debit, v_credit, (v_debit - v_credit)
                        USING ERRCODE = \'23514\';
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::unprepared('
            CREATE OR REPLACE FUNCTION '.self::S.'.trg_line_balanced()
            RETURNS TRIGGER AS $$
            BEGIN
                PERFORM '.self::S.'.assert_entry_balanced(
                    CASE WHEN TG_OP = \'DELETE\' THEN OLD.journal_entry_id ELSE NEW.journal_entry_id END
                );
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::unprepared('
            CREATE OR REPLACE FUNCTION '.self::S.'.trg_entry_balanced()
            RETURNS TRIGGER AS $$
            BEGIN
                PERFORM '.self::S.'.assert_entry_balanced(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ');

        /*
         * DUA TRIGGER, DAN KEDUANYA PERLU.
         *
         * Pada journal_lines: menangkap baris yang ditambah, diubah, atau
         * dihapus sehingga jurnalnya jadi miring.
         *
         * Pada journal_entries: menangkap header yang disisipkan TANPA
         * baris sama sekali. Tanpa trigger kedua, jurnal kosong lolos —
         * karena tidak ada satu pun baris yang memicu trigger pertama.
         */
        DB::unprepared('
            CREATE CONSTRAINT TRIGGER journal_lines_balanced
            AFTER INSERT OR UPDATE OR DELETE ON '.self::S.'.journal_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION '.self::S.'.trg_line_balanced();
        ');

        DB::unprepared('
            CREATE CONSTRAINT TRIGGER journal_entries_balanced
            AFTER INSERT ON '.self::S.'.journal_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION '.self::S.'.trg_entry_balanced();
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_balanced ON '.self::S.'.journal_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balanced ON '.self::S.'.journal_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::S.'.trg_entry_balanced()');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::S.'.trg_line_balanced()');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::S.'.assert_entry_balanced(bigint)');
    }
};
