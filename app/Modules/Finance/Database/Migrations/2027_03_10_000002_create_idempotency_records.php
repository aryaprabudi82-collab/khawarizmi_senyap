<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan idempotensi — penahan transaksi finansial ganda.
 *
 * TEMUAN DISCOVERY TAHAP 0: NOL kolom dan NOL tabel idempotency di seluruh
 * basis data. Tidak ada perlindungan apa pun terhadap kiriman ganda.
 *
 * MENGAPA INI BUKAN KEKHAWATIRAN TEORETIS. Pada beban 2.000 pasien/hari
 * dengan jaringan rumah sakit yang tidak selalu stabil, kejadian yang
 * pasti berulang: petugas menekan "Simpan", layar diam beberapa detik
 * karena jaringan tersendat, petugas menekan lagi. Tanpa penahan,
 * pasiennya ditagih dua kali — dan yang menemukannya biasanya pasien,
 * bukan sistem.
 *
 * YANG DISIMPAN BUKAN CUMA KUNCINYA, TAPI JAWABANNYA. Permintaan ulang
 * dengan kunci yang sama harus menerima JAWABAN YANG SAMA, bukan galat.
 * Kalau yang dikembalikan galat, petugas akan mengira simpanannya gagal
 * lalu mencoba cara lain — dan justru itu yang melahirkan baris kembar
 * dengan kunci berbeda.
 *
 * SIDIK PERMINTAAN IKUT DISIMPAN. Kunci yang sama dengan isi yang BERBEDA
 * bukan pengiriman ulang melainkan kekeliruan pemanggil — mungkin kunci
 * yang dipakai ulang untuk transaksi lain. Itu ditolak terang-terangan,
 * bukan diam-diam mengembalikan jawaban transaksi yang berbeda.
 *
 * TTL, BUKAN SELAMANYA. Catatan ini penahan terhadap pengiriman ulang
 * yang terjadi dalam hitungan detik sampai jam, bukan jejak audit —
 * jejaknya ada di platform.audit_logs. Menyimpannya selamanya membuat
 * tabel ini tumbuh sebesar tabel transaksinya sendiri tanpa menambah
 * satu pun perlindungan.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        Schema::create(self::S.'.idempotency_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Kunci DIRUANGKAN per jenis operasi. Kunci "abc-123" untuk
             * pencatatan charge dan untuk pembayaran adalah dua hal yang
             * berbeda; tanpa ruang nama, keduanya bertabrakan dan yang
             * kedua akan menerima jawaban milik yang pertama.
             */
            $table->string('scope', 60)->comment('Jenis operasi, mis. billing.charge, kasir.payment');
            $table->string('idempotency_key', 120);

            /* Sidik isi permintaan (SHA-256), untuk membedakan kiriman ulang dari penyalahgunaan kunci. */
            $table->char('request_fingerprint', 64);

            $table->string('status', 20)->default('diproses')->comment('diproses, selesai, gagal');

            /* Jawaban yang dikembalikan ke pemanggil saat kiriman ulang. */
            $table->jsonb('response')->nullable();

            /* Rujukan ke baris yang terbentuk — supaya bisa ditelusuri balik. */
            $table->string('resource_type', 80)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->unsignedBigInteger('actor_id')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->string('correlation_id', 64)->nullable()->comment('Penelusuran lintas modul');

            $table->timestampTz('expires_at');
            $table->timestampsTz();

            /*
             * UNIQUE inilah penahannya, dan ia harus ada di BASIS DATA —
             * pemeriksaan "sudah ada belum?" di aplikasi kalah oleh dua
             * permintaan yang tiba bersamaan: keduanya memeriksa,
             * keduanya tidak menemukan apa-apa, keduanya menulis.
             */
            $table->unique(['scope', 'idempotency_key']);
            $table->index('expires_at');
            $table->index(['resource_type', 'resource_id']);
        });

        DB::statement('ALTER TABLE '.self::S.'.idempotency_records ADD CONSTRAINT idempotency_status_check
            CHECK (status IN (\'diproses\',\'selesai\',\'gagal\'))');

        DB::statement('ALTER TABLE '.self::S.'.idempotency_records ADD CONSTRAINT idempotency_key_not_blank
            CHECK (btrim(idempotency_key) <> \'\')');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.idempotency_records');
    }
};
