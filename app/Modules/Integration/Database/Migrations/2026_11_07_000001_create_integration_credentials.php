<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kredensial integrasi: rumah untuk sistem luar (domain L).
 *
 * SEBELUM INI kredensial hanya ada di berkas .env. Mengisinya menuntut
 * akses server dan penerapan ulang aplikasi — pekerjaan yang tidak bisa
 * dilakukan orang yang memegang kredensialnya, yang biasanya petugas
 * rekam medis atau kepala IT, bukan yang punya akses shell. Akibatnya
 * kredensial menunggu di email berhari-hari sampai ada yang sempat
 * memasangnya. Tabel ini memindahkan pengisian ke dalam aplikasi.
 *
 * NILAINYA DISIMPAN TERENKRIPSI. Kolom value memakai cast terenkripsi
 * Laravel, jadi isinya tidak terbaca dari basis data maupun dari salinan
 * cadangan. Yang perlu diingat: enkripsinya bergantung pada APP_KEY —
 * kehilangan APP_KEY berarti kehilangan seluruh kredensial ini, dan itu
 * disebut terang di layarnya supaya tidak jadi kejutan saat pemulihan.
 *
 * KOLOM RAHASIA TIDAK PERNAH DIKEMBALIKAN UTUH KE LAYAR. Yang tampil cuma
 * penanda bahwa ia terisi berikut empat huruf terakhirnya — cukup untuk
 * memastikan yang terpasang benar, tidak cukup untuk menyalinnya.
 *
 * SATU BARIS PER KOLOM, bukan satu baris per sistem dengan JSON. Alasannya
 * praktis: kolom yang dibutuhkan tiap sistem berbeda dan bertambah seiring
 * waktu, dan menyimpannya sebagai JSON membuat "sistem mana yang belum
 * lengkap" tidak bisa dijawab dengan kueri — padahal justru itu pertanyaan
 * yang paling sering diajukan.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.integration_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('system', 30)->comment('Kunci sistem pada IntegrationRegistry');
            $table->string('field', 40);

            // Terenkripsi lewat cast model. Teks panjang karena ciphertext
            // jauh lebih panjang daripada nilai aslinya.
            $table->text('value')->nullable();

            // Empat huruf terakhir nilai asli, disimpan TERPISAH dan tidak
            // terenkripsi. Dipakai layar untuk memastikan yang terpasang
            // benar tanpa perlu mendekripsi apa pun.
            $table->string('tail', 8)->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name', 120)->nullable();

            $table->timestampsTz();

            $table->unique(['system', 'field'], 'integration_credential_unik');
            $table->index('system');
        });

        Schema::create(self::S . '.integration_health_checks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('system', 30);

            $table->boolean('success')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('message', 300)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampTz('checked_at');

            $table->timestampsTz();

            $table->index(['system', 'checked_at']);
        });

        DB::statement('COMMENT ON TABLE ' . self::S . ".integration_credentials IS
            'Kredensial sistem luar. Nilainya terenkripsi dengan APP_KEY; kehilangan APP_KEY berarti kehilangan isinya.'");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.integration_health_checks');
        Schema::dropIfExists(self::S . '.integration_credentials');
    }
};
