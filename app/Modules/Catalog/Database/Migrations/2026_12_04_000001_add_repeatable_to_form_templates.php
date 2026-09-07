<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Template yang memang diisi BERULANG (domain M item D).
 *
 * KOREKSI TERHADAP ITEM A, dan sebabnya baru terlihat setelah EWS
 * diperiksa. Item A memasang aturan "satu formulir per kunjungan per
 * template", dan itu benar untuk asesmen: dua asesmen awal atas kunjungan
 * yang sama berarti dua penilaian yang bisa bertentangan tanpa ada yang
 * tahu mana yang berlaku.
 *
 * Tapi ada instrumen yang justru HARUS diisi berulang. Early Warning Score
 * dinilai setiap beberapa jam selama pasien dirawat — itulah gunanya:
 * menangkap perburukan yang tidak terlihat pada satu titik waktu. Aturan
 * item A akan menahan penilaian kedua, dan pasien yang memburuk pukul tiga
 * pagi tidak akan punya barisnya.
 *
 * TEMPLATENYA SENDIRI YANG MENYATAKAN SIFATNYA, bukan pemanggilnya. Kalau
 * pemanggil yang memutuskan, dua layar berbeda bisa memperlakukan
 * instrumen yang sama secara berbeda, dan tidak ada yang menahannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog.form_templates', function (Blueprint $table) {
            $table->boolean('is_repeatable')->default(false)
                ->comment('true untuk instrumen pemantauan yang memang dinilai berulang, mis. EWS');
        });

        DB::statement('CREATE OR REPLACE VIEW catalog.v_form_template AS
            SELECT
                id          AS template_id,
                code,
                version,
                name,
                category,
                specialty,
                age_group,
                sections,
                scoring,
                note,
                is_active,
                -- DITAMBAHKAN DI AKHIR, bukan disisipkan: CREATE OR REPLACE VIEW
                -- PostgreSQL hanya bisa menambah kolom di ujung daftar, dan
                -- menyisipkannya gagal dengan pesan "cannot change name of
                -- view column" yang menunjuk kolom yang sama sekali lain.
                is_repeatable
            FROM catalog.form_templates');
    }

    public function down(): void
    {
        DB::statement('CREATE OR REPLACE VIEW catalog.v_form_template AS
            SELECT
                id          AS template_id,
                code,
                version,
                name,
                category,
                specialty,
                age_group,
                sections,
                scoring,
                note,
                is_active
            FROM catalog.form_templates');

        Schema::table('catalog.form_templates', function (Blueprint $table) {
            $table->dropColumn('is_repeatable');
        });
    }
};
