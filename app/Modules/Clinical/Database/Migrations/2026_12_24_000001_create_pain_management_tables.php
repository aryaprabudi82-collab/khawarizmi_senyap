<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengelolaan nyeri (domain M item O).
 *
 * Menaungi penilaian_ulang_nyeri, intervensi_nyeri_farmakologi, dan
 * intervensi_nyeri_nonfarmakologi.
 *
 * LINGKARANNYA PUTUS DI KHANZA, DAN ITU TEMUAN POKOK ITEM INI.
 * intervensi_nyeri_farmakologi dan intervensi_nyeri_nonfarmakologi
 * punya kode izin tapi TIDAK PUNYA TABEL sama sekali di skema — sudah
 * diperiksa. Artinya Khanza bisa mencatat nyeri pasien dan bisa
 * mencatat penilaian ulangnya, tapi tidak punya tempat untuk apa yang
 * dikerjakan di antara keduanya.
 *
 * Padahal justru itu yang dituntut akreditasi: nyeri dinilai,
 * ditangani, lalu DINILAI ULANG untuk membuktikan penanganannya
 * bekerja. Tanpa tabel intervensi, penilaian ulang yang skornya tetap
 * tinggi tidak bisa dijawab pertanyaan "memangnya sudah diapakan?".
 *
 * JENIS SKALA DICATAT, DAN INI TIDAK ADA DI KHANZA. skala_nyeri Khanza
 * enum '0' sampai '10' tanpa menyebut alat ukurnya. Angka 3 dari FLACC
 * pada bayi dan angka 3 dari NRS pada dewasa bukan hal yang sama, dan
 * pasien tidak sadar dinilai dengan CPOT yang skalanya bahkan 0-8.
 * Menyimpan semuanya sebagai satu angka tanpa alatnya membuat angka
 * yang tidak sebanding terbaca sebanding — dan tren nyeri seorang
 * pasien yang alat ukurnya berganti akan tampak membaik atau memburuk
 * tanpa ada yang berubah pada pasiennya.
 *
 * SKALA JADI ANGKA, bukan enum teks. Khanza menyimpannya sebagai
 * enum('0','1',...,'10') sehingga tidak bisa dirata-rata maupun
 * ditrenkan tanpa dikonversi lebih dulu. Nol tetap nilai yang sah dan
 * diperlakukan begitu: "tidak nyeri" adalah hasil penilaian, bukan
 * penilaian yang belum diisi.
 *
 * YANG MEREDAKAN NYERI ADALAH DAFTAR. nyeri_hilang Khanza enum satu
 * pilihan — istirahat, mendengar musik, minum obat, atau tidak diisi.
 * Pasien yang nyerinya reda dengan obat DAN perubahan posisi harus
 * memilih salah satu, padahal keduanya informasi yang menentukan
 * rencana penanganan berikutnya.
 *
 * INTERVENSI MENUNJUK PENILAIAN YANG DIRESPONSNYA, dan penilaian ulang
 * menunjuk intervensi yang dievaluasinya. Rantai itu yang membuat
 * pertanyaan "apakah nyerinya berkurang setelah ditangani" bisa dijawab
 * dari data alih-alih dibaca satu per satu dari catatan bebas.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createAssessments();
        $this->createInterventions();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.pain_interventions');
        Schema::dropIfExists(self::S.'.pain_assessments');
    }

    private function createAssessments(): void
    {
        Schema::create(self::S.'.pain_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('assessed_at');
            $table->string('kind', 20)->comment('tidak-ada, akut, kronis');

            // Alat ukurnya, bukan cuma angkanya — lihat catatan kelas.
            $table->string('scale_type', 20)
                ->comment('nrs, wong-baker, flacc, cpot, bps — skala maksimumnya berbeda-beda');
            $table->unsignedSmallInteger('score')
                ->comment('Angka, bukan enum teks. Nol adalah hasil penilaian yang sah');

            $table->string('provokes', 30)->nullable()->comment('proses-penyakit, benturan, gerakan, lain-lain');
            $table->string('provokes_note', 100)->nullable();
            $table->string('quality', 30)->nullable()
                ->comment('tertusuk, berdenyut, teriris, tertindih, terbakar, kram, lain-lain');
            $table->string('quality_note', 100)->nullable();
            $table->string('location', 100)->nullable();
            $table->boolean('radiates')->nullable()->comment('NULL berarti belum ditanyakan');
            $table->string('radiates_to', 100)->nullable();
            $table->string('duration', 50)->nullable();

            // DAFTAR, bukan satu pilihan.
            $table->jsonb('relieved_by')->default(DB::raw("'[]'::jsonb"));
            $table->string('relieved_by_note', 150)->nullable();

            // Terisi bila penilaian ini adalah evaluasi atas sebuah
            // intervensi — itulah yang menutup lingkarannya.
            $table->unsignedBigInteger('evaluates_intervention_id')->nullable();

            $table->unsignedBigInteger('assessed_by')->nullable();
            $table->string('assessed_by_name', 150);

            $table->text('note')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'assessed_at']);
            $table->index(['patient_id', 'assessed_at']);
            $table->index('evaluates_intervention_id');
        });

        DB::statement('ALTER TABLE '.self::S.".pain_assessments
            ADD CONSTRAINT pain_assessments_kind_check
            CHECK (kind IN ('tidak-ada','akut','kronis'))");

        DB::statement('ALTER TABLE '.self::S.".pain_assessments
            ADD CONSTRAINT pain_assessments_scale_type_check
            CHECK (scale_type IN ('nrs','wong-baker','flacc','cpot','bps'))");

        // Batas atas berbeda per alat ukur, dan memakai satu batas untuk
        // semuanya membuat skor CPOT 9 lolos padahal alatnya berhenti di 8.
        DB::statement('ALTER TABLE '.self::S.".pain_assessments
            ADD CONSTRAINT pain_assessments_score_range_check
            CHECK ((scale_type IN ('nrs','wong-baker','flacc') AND score BETWEEN 0 AND 10)
                   OR (scale_type = 'cpot' AND score BETWEEN 0 AND 8)
                   OR (scale_type = 'bps' AND score BETWEEN 3 AND 12))");

        // Nyeri yang dinyatakan tidak ada harus berskor serendah-rendahnya
        // menurut alat ukurnya — dan BPS memang mulai dari 3, bukan 0,
        // karena pasien terventilasi selalu punya nilai dasar.
        DB::statement('ALTER TABLE '.self::S.".pain_assessments
            ADD CONSTRAINT pain_assessments_none_check
            CHECK (kind <> 'tidak-ada'
                   OR score = CASE WHEN scale_type = 'bps' THEN 3 ELSE 0 END)");

        DB::statement('ALTER TABLE '.self::S.".pain_assessments
            ADD CONSTRAINT pain_assessments_relieved_check
            CHECK (jsonb_typeof(relieved_by) = 'array')");
    }

    private function createInterventions(): void
    {
        Schema::create(self::S.'.pain_interventions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Menunjuk nyeri yang diresponsnya. WAJIB: intervensi nyeri
            // yang tidak menunjuk penilaian apa pun tidak bisa dievaluasi,
            // dan tidak bisa dijawab apakah ia bekerja.
            $table->unsignedBigInteger('assessment_id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            $table->string('kind', 20)->comment('farmakologi, nonfarmakologi');
            $table->string('method', 100)
                ->comment('Nama obat, atau tindakan seperti kompres hangat, reposisi, relaksasi napas');
            $table->string('dose', 60)->nullable();
            $table->string('route', 40)->nullable();
            $table->unsignedBigInteger('drug_id')->nullable()
                ->comment('Terisi bila obatnya ada di formularium; tidak diwajibkan');

            $table->timestampTz('given_at');
            $table->unsignedBigInteger('given_by')->nullable();
            $table->string('given_by_name', 150);

            $table->text('note')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['assessment_id', 'given_at']);
            $table->index(['registration_id', 'given_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".pain_interventions
            ADD CONSTRAINT pain_interventions_kind_check
            CHECK (kind IN ('farmakologi','nonfarmakologi'))");

        // Intervensi farmakologi harus menyebut dosis dan rutenya: "diberi
        // analgetik" tanpa keduanya bukan instruksi yang bisa ditelusuri,
        // dan tidak bisa dibandingkan saat nyerinya tidak berkurang.
        DB::statement('ALTER TABLE '.self::S.".pain_interventions
            ADD CONSTRAINT pain_interventions_pharmacological_check
            CHECK (kind <> 'farmakologi'
                   OR (dose IS NOT NULL AND btrim(dose) <> ''
                       AND route IS NOT NULL AND btrim(route) <> ''))");
    }
};
