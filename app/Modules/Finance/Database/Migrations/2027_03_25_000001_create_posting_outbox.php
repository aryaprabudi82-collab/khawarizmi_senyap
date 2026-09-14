<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox Posting Engine — kerangka, Wave 1 butir 1.11.
 *
 * MENGAPA OUTBOX, BUKAN MEMANGGIL QUEUE LANGSUNG.
 *
 * Posting ke GL harus ASINKRON: charge capture tidak boleh menunggu
 * jurnal selesai, apalagi pada beban puncak 08.00-12.00 dengan 2.000
 * pasien sehari. Cara yang tampak paling mudah adalah `dispatch(job)`
 * tepat setelah transaksi disimpan — dan cara itu punya lubang yang
 * hanya muncul saat sistem sedang sibuk:
 *
 *   - Transaksi COMMIT, lalu proses mati sebelum job terkirim ke queue.
 *     Transaksinya ada, jurnalnya tidak akan pernah ada, dan tidak ada
 *     satu pun galat.
 *   - Job terkirim, lalu transaksinya ROLLBACK. Worker menjurnalkan
 *     transaksi yang tidak pernah terjadi.
 *
 * Outbox menutup keduanya: baris outbox ditulis DI DALAM transaksi yang
 * sama dengan transaksinya. Kalau transaksinya batal, niat postingnya
 * ikut batal. Kalau transaksinya jadi, niat postingnya pasti ada — dan
 * worker akan menemukannya, cepat atau lambat.
 *
 * IDEMPOTENSI BUKAN PILIHAN DI SINI. Worker bisa mengambil baris yang
 * sama dua kali (proses mati setelah menjurnal tapi sebelum menandai
 * selesai), jadi penahannya ada di dua tempat: `dedupe_key` unik di
 * tabel ini, dan pemeriksaan jurnal-sudah-ada di enginenya.
 *
 * DEAD LETTER: baris yang gagal berkali-kali TIDAK dibuang dan TIDAK
 * dicoba selamanya. Ia berhenti di status `gagal` berikut pesannya, dan
 * dilaporkan. Baris yang dicoba selamanya akan membanjiri log sampai
 * kegagalan lain tidak terlihat; baris yang dibuang menghilangkan
 * transaksi dari buku besar tanpa jejak.
 */
return new class extends Migration
{
    private const S = 'finance';

    private const STATUS = ['menunggu', 'diproses', 'selesai', 'gagal'];

    public function up(): void
    {
        Schema::create(self::S.'.posting_outbox', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Jenis peristiwa menentukan template jurnalnya. Sengaja
             * string bebas, bukan enum: menambah jenis transaksi baru
             * tidak boleh menuntut migrasi — dan daftar jenisnya akan
             * tumbuh sepanjang Wave 2 sampai 6.
             */
            $table->string('event_type', 60);

            $table->string('source_context', 30)
                ->comment('Konteks asal: billing, pharmacy, inventory, kasir, ...');
            $table->string('source_type', 80)
                ->comment('Jenis dokumen asal, mis. invoice, payment, stock_movement');
            $table->unsignedBigInteger('source_id');

            /*
             * DEDUPE KEY — penahan utama. Satu peristiwa sumber hanya
             * boleh menghasilkan satu jurnal, berapa kali pun ia
             * dimasukkan ke outbox.
             */
            $table->string('dedupe_key', 160)->unique();

            /*
             * MUATANNYA DIBEKUKAN, tidak dibaca ulang dari sumbernya saat
             * worker berjalan. Kalau dibaca ulang, transaksi yang
             * dikoreksi setelah masuk outbox akan dijurnalkan dengan nilai
             * barunya — padahal yang seharusnya dijurnal adalah nilai saat
             * peristiwanya terjadi, dan koreksinya jadi jurnal tersendiri.
             */
            $table->jsonb('payload');

            /* Tanggal peristiwa — menentukan periode akuntansi jurnalnya. */
            $table->date('occurred_on');

            $table->string('status', 15)->default('menunggu');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unsignedBigInteger('journal_entry_id')->nullable()
                ->comment('Jurnal yang dihasilkan, terisi saat selesai');

            $table->string('correlation_id', 64)->nullable()
                ->comment('Penelusuran lintas modul');

            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            /*
             * Indeks pengambil kerja worker. Parsial: worker hanya
             * mencari baris yang masih menunggu, dan indeks atas seluruh
             * baris akan terus membesar mengikuti riwayat yang sudah
             * selesai — padahal riwayat itu tidak pernah dicari worker.
             */
            $table->index(['source_context', 'source_type', 'source_id']);
            $table->index('status');
        });

        DB::statement('CREATE INDEX posting_outbox_antrean
            ON '.self::S.'.posting_outbox (next_attempt_at NULLS FIRST, id)
            WHERE status = \'menunggu\'');

        DB::statement('ALTER TABLE '.self::S.'.posting_outbox ADD CONSTRAINT posting_outbox_status_check
            CHECK (status IN (\''.implode("','", self::STATUS).'\'))');

        /*
         * Baris yang SELESAI wajib menunjuk jurnalnya. Tanpa itu, tidak
         * ada cara menelusuri dari transaksi ke jurnalnya — dan
         * penelusuran balik itu justru yang dicari saat ada selisih.
         */
        DB::statement('ALTER TABLE '.self::S.'.posting_outbox ADD CONSTRAINT posting_outbox_selesai_berjurnal
            CHECK (status <> \'selesai\' OR journal_entry_id IS NOT NULL)');

        /* Baris yang GAGAL wajib menyebut sebabnya. */
        DB::statement('ALTER TABLE '.self::S.'.posting_outbox ADD CONSTRAINT posting_outbox_gagal_beralasan
            CHECK (status <> \'gagal\' OR (last_error IS NOT NULL AND btrim(last_error) <> \'\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.posting_outbox');
    }
};
