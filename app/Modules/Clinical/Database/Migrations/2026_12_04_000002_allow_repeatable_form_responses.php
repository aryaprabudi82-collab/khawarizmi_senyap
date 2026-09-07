<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Melonggarkan aturan "satu formulir per kunjungan" untuk instrumen yang
 * memang dinilai berulang (domain M item D).
 *
 * SIFAT BERULANG IKUT DIBEKUKAN DI JAWABANNYA, bukan dibaca dari template
 * saat dibutuhkan. Dua alasan, dan keduanya menentukan:
 *
 * 1. Indeks parsial tidak bisa merujuk kolom tabel lain, apalagi lintas
 *    schema. Aturannya ditegakkan basis data, jadi bahannya harus ada di
 *    tabel yang sama.
 *
 * 2. Lebih penting: sifatnya bisa berubah. Kalau sebuah template yang
 *    semula berulang kemudian dijadikan sekali-isi, jawaban lama yang
 *    sudah terlanjur banyak akan mendadak melanggar aturan yang belum
 *    berlaku saat ia ditulis. Membekukannya membuat setiap jawaban
 *    dinilai dengan aturan yang berlaku ketika ia dibuat — prinsip yang
 *    sama seperti versi template dan skornya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical.form_responses', function (Blueprint $table) {
            $table->boolean('is_repeatable')->default(false)
                ->comment('Disalin dari template saat formulir dibuka; lihat catatan migrasi');
        });

        DB::statement('DROP INDEX IF EXISTS clinical.form_response_kunjungan_unique');

        DB::statement("CREATE UNIQUE INDEX form_response_kunjungan_unique
            ON clinical.form_responses (registration_id, template_code)
            WHERE status <> 'dibatalkan'
              AND deleted_at IS NULL
              AND NOT is_repeatable");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clinical.form_response_kunjungan_unique');

        DB::statement("CREATE UNIQUE INDEX form_response_kunjungan_unique
            ON clinical.form_responses (registration_id, template_code)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");

        Schema::table('clinical.form_responses', function (Blueprint $table) {
            $table->dropColumn('is_repeatable');
        });
    }
};
