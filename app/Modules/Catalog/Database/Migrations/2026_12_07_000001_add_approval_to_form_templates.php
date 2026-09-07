<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengesahan template formulir (domain M item F).
 *
 * KENAPA INI ADA. Item A membuat formulir jadi data, dan itu berarti
 * formulir bisa dibuat siapa saja yang punya akses — termasuk oleh
 * pemrogram yang menyiapkan data awal. Formulir asesmen yang tampak resmi
 * tapi tidak pernah disepakati komite medik adalah masalah yang nyata:
 * isinya masuk ke rekam medis pasien, dipakai mengambil keputusan klinis,
 * dan dibaca sebagai standar rumah sakit padahal bukan.
 *
 * TIDAK ADA YANG DISAHKAN OLEH PENGISIAN DATA AWAL. Seluruh template yang
 * disemai — termasuk instrumen baku seperti Morse, Braden, dan Aldrete —
 * masuk dengan is_approved = false. Instrumen bakunya memang sahih sebagai
 * instrumen; yang belum terjadi adalah RSP UI MENGADOPSINYA. Kesetiaan
 * pada instrumen aslinya tidak menggantikan keputusan rumah sakit untuk
 * memakainya.
 *
 * TEMPLATE YANG BELUM DISAHKAN TETAP BISA DIPAKAI, dan itu keputusan yang
 * disengaja. Menghalanginya berarti sistem tidak bisa dipakai sampai
 * komite bersidang, dan yang terjadi kemudian adalah pencatatan di kertas
 * — jauh lebih buruk daripada pencatatan digital yang statusnya jujur.
 * Yang dilakukan justru sebaliknya: statusnya DIBEKUKAN pada tiap jawaban,
 * sehingga kelak bisa dijawab dengan pasti rekam medis mana yang dibuat
 * memakai formulir yang belum disahkan.
 *
 * REVISI MENGEMBALIKAN STATUS KE BELUM DISAHKAN. Versi baru adalah
 * pertanyaan baru; pengesahan versi lama tidak berlaku untuknya. Kalau
 * status ikut terbawa, satu revisi diam-diam bisa mengubah isi formulir
 * yang sudah disahkan tanpa ada yang menyetujuinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog.form_templates', function (Blueprint $table) {
            $table->boolean('is_approved')->default(false)
                ->comment('Disahkan komite medik RSP UI; data awal SELALU false');
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 150)->nullable();
            $table->string('approval_note', 300)->nullable()
                ->comment('Nomor keputusan atau berita acara pengesahan');
        });

        // Kolom BARU DITAMBAHKAN DI AKHIR: CREATE OR REPLACE VIEW
        // PostgreSQL tidak bisa menyisipkan kolom di tengah daftar.
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
                is_repeatable,
                is_approved,
                approved_at,
                approved_by_name,
                approval_note
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
                is_active,
                is_repeatable
            FROM catalog.form_templates');

        Schema::table('catalog.form_templates', function (Blueprint $table) {
            $table->dropColumn(['is_approved', 'approved_at', 'approved_by', 'approved_by_name', 'approval_note']);
        });
    }
};
