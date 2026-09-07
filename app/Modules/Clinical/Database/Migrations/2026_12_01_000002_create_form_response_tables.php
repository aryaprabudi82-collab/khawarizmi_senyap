<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jawaban formulir asesmen & skrining (domain M item A).
 *
 * Pasangan dari catalog.form_templates: templatenya data referensi milik
 * catalog, jawabannya rekam medis milik clinical. Pemisahan itu bukan
 * kerapian — template diubah admin, jawaban ditulis klinisi, dan keduanya
 * punya aturan penyuntingan yang sama sekali berbeda.
 *
 * VERSI TEMPLATE DIBEKUKAN DI SINI. Yang disimpan bukan cuma kode
 * templatenya, tapi versi yang benar-benar dipakai saat mengisi. Tanpa itu,
 * asesmen tahun lalu akan dibaca ulang dengan pertanyaan dan ambang tahun
 * ini — dan yang tertulis di rekam medis berubah tanpa ada yang
 * menyentuhnya.
 *
 * SKOR DISIMPAN, TIDAK DIHITUNG ULANG — dan ini KEBALIKAN dari aturan
 * durasi pada indikator mutu (domain J item D), jadi bedanya perlu
 * dinyatakan. Durasi diturunkan dari dua stempel waktu yang keduanya
 * adalah fakta yang tercatat; menghitungnya ulang selalu memberi jawaban
 * yang sama, dan menyimpannya justru membuatnya basi saat stempelnya
 * diperbaiki. Skor skrining diturunkan dari RULEBOOK yang bisa direvisi:
 * menghitungnya ulang dengan pedoman baru akan mengubah penilaian klinis
 * yang sudah dinyatakan seseorang. Karena itu skor dibekukan bersama versi
 * templatenya.
 *
 * DRAF DIBEDAKAN DARI FINAL, dan hanya yang final yang dianggap bagian
 * rekam medis. Asesmen yang belum selesai diisi bukan pernyataan klinis
 * siapa pun — aturan yang sama seperti asesmen SOAP di domain L item O.
 *
 * FORMULIR YANG BELUM DIISI TIDAK MENGHASILKAN BARIS. "Belum diskrining"
 * dan "sudah diskrining, hasilnya negatif" adalah dua pernyataan yang
 * berbeda, dan baris kosong berisi nol akan membuat keduanya tampak sama.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.form_responses', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            // Template DAN versinya. Tanpa foreign key lintas schema; yang
            // dijaga adalah nilainya dibekukan, bukan relasinya ditegakkan.
            $table->string('template_code', 60);
            $table->unsignedSmallInteger('template_version');
            $table->string('template_name', 150)->comment('Disalin: nama template boleh berubah, yang tercatat tidak');
            $table->string('category', 30);

            $table->json('answers');

            // Skor dan tafsirnya, DIBEKUKAN bersama versi templatenya.
            $table->integer('score')->nullable();
            $table->string('interpretation', 200)->nullable();
            $table->string('risk_level', 30)->nullable();

            $table->text('note')->nullable();

            $table->string('status', 20)->default('draf');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'category']);
            $table->index(['patient_id', 'template_code']);
            $table->index(['template_code', 'finalized_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".form_responses
            ADD CONSTRAINT form_responses_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        // Yang final wajib punya waktu finalisasi: status final tanpa
        // stempel waktu membuat "sejak kapan ini jadi rekam medis" tidak
        // bisa dijawab, dan itu pertanyaan pertama saat ada sengketa.
        DB::statement('ALTER TABLE ' . self::S . ".form_responses
            ADD CONSTRAINT form_responses_final_check
            CHECK (status <> 'final' OR finalized_at IS NOT NULL)");

        // Satu formulir jenis ini per kunjungan, selama belum dibatalkan.
        // Dua asesmen awal atas kunjungan yang sama berarti dua penilaian
        // yang bisa saling bertentangan tanpa ada yang tahu mana yang
        // berlaku. Pengkajian ULANG punya templatenya sendiri.
        DB::statement('CREATE UNIQUE INDEX form_response_kunjungan_unique
            ON ' . self::S . ".form_responses (registration_id, template_code)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.form_responses');
    }
};
