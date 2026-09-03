<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sekrining_rawat_jalan (Khanza domain A, kelas RMSKriningRawatJalan) —
 * tercatat context=encounter di platform.permissions, tapi kelas Java-nya ada
 * di package "rekammedis" (lihat Khanza_Functional_Dependency_Map.xlsx
 * sheet2) — jadi dibangun di sini, bukan encounter. Sama seperti PPI/K3 yang
 * pindah dari domain C ke quality: domain huruf Khanza tidak selalu sama
 * dengan pemilik konseptualnya.
 *
 * Skrining awal rawat jalan — empat domain skrining standar akreditasi RS
 * (risiko jatuh, nyeri, gizi, gejala penyakit menular) dicatat SEKALI per
 * kunjungan sebelum asesmen penuh (SOAP/asesmen keperawatan) dimulai. Beda
 * bentuk dari clinical.assessments: terstruktur (skor/level), bukan naratif,
 * dan dicatat oleh siapa pun kontak pertama dengan pasien (bisa perawat,
 * bisa dokter) — bukan dokumen yang direvisi berjenjang seperti SOAP,
 * jadi TIDAK memakai pola draft/final/amended assessments — sekali dicatat,
 * selesai (perawat memverifikasi ulang dengan pasien kalau meragukan,
 * bukan menyunting catatan lama).
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.screenings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id')->unique();
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->string('fall_risk_level', 10)->comment('rendah, sedang, tinggi');
            $table->unsignedTinyInteger('pain_score')->comment('Skala nyeri 0-10');
            $table->boolean('nutrition_at_risk')->comment('Skrining gizi cepat: penurunan BB tanpa sengaja + nafsu makan menurun');
            $table->boolean('infectious_symptom')->comment('Batuk/demam/gejala pernapasan menular');
            $table->text('special_needs')->nullable()->comment('Kebutuhan komunikasi/disabilitas, dsb.');

            $table->unsignedBigInteger('screened_by')->nullable();
            $table->string('screened_by_name', 150)->nullable();
            $table->timestampTz('screened_at');

            $table->timestampsTz();

            $table->index(['patient_id', 'screened_at']);
        });

        DB::statement("ALTER TABLE " . self::S . ".screenings ADD CONSTRAINT screenings_fall_risk_check
            CHECK (fall_risk_level IN ('rendah','sedang','tinggi'))");
        DB::statement("ALTER TABLE " . self::S . ".screenings ADD CONSTRAINT screenings_pain_score_check
            CHECK (pain_score BETWEEN 0 AND 10)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.screenings');
    }
};
