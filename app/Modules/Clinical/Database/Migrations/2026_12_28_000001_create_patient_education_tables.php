<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edukasi pasien & keluarga (domain M item Q).
 *
 * Menaungi edukasi_pasien_keluarga_rj, pelaksanaan_informasi_edukasi,
 * bukti_pelaksanaan_informasi_edukasi, dan
 * template_pelaksanaan_informasi_edukasi.
 *
 * PERTANYAAN KEYAKINAN TIDAK BOLEH DIWAJIBKAN, DAN INI TEMUAN POKOK
 * ITEM INI. edukasi_pasien_keluarga_rj Khanza memasang
 * penyakitnya_merupakan enum('Ujian/Cobaan','Kutukan','Lain-lain')
 * sebagai NOT NULL — setiap pasien WAJIB dikategorikan keyakinannya
 * tentang penyakitnya, dari dua pilihan itu, tanpa kemungkinan "belum
 * ditanyakan". keputusan_memilih_layanan dan keyakinan_terhadap_terapi
 * mengikuti pola yang sama.
 *
 * Pertanyaan keyakinan yang diwajibkan dengan kosakata sesempit itu
 * hanya menghasilkan dua kemungkinan: isian asal-asalan yang mengotori
 * data, atau petugas yang memaksakan label pada keyakinan orang lain.
 * Keduanya buruk, dan yang kedua lebih buruk. Di sini ketiganya BOLEH
 * KOSONG — aturan "null berarti belum ditanyakan" yang sudah berlaku
 * sejak domain L, dengan alasan yang di sini lebih berat daripada
 * sekadar kebersihan data.
 *
 * CARA BELAJAR DAN HAMBATAN BELAJAR ADALAH DAFTAR. Keduanya enum satu
 * pilihan di Khanza, padahal pasien yang paling perlu diperhatikan
 * justru yang punya beberapa sekaligus: nyeri DAN buta huruf, atau
 * gangguan kognitif DAN takut. Memaksa memilih satu berarti membuang
 * hambatan yang lain, dan edukasi yang disusun akan gagal karena
 * hambatan yang dibuang itu.
 *
 * PENGULANGAN MENUNJUK APA YANG DIULANG.
 * pelaksanaan_informasi_edukasi punya status enum('Awal','Ulang') yang
 * menyatakan sebuah edukasi adalah pengulangan tapi tidak menyebut
 * pengulangan ATAS APA. Akibatnya tidak bisa dijawab apakah materi yang
 * gagal diverifikasi benar-benar diulang, atau yang diulang justru
 * materi lain yang sudah dimengerti.
 *
 * LAMA EDUKASI DIHITUNG dari jam mulai dan selesai, bukan diketik
 * seperti lama_edukasi varchar(10) Khanza.
 *
 * FOTO BUKTI TIDAK DIBUATKAN TABEL BERKAS KEEMPAT.
 * bukti_pelaksanaan_informasi_edukasi Khanza cuma menyimpan jalur foto
 * tanpa pengunggah maupun waktunya — persoalan yang sama sudah
 * diselesaikan item L. Buktinya dilampirkan sebagai berkas rekam medis
 * biasa berjenis BUKTI-EDUKASI, lengkap dengan pengunggah dan sidiknya.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createLearningAssessments();
        $this->createEducationSessions();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.education_sessions');
        Schema::dropIfExists(self::S.'.learning_assessments');
    }

    private function createLearningAssessments(): void
    {
        Schema::create(self::S.'.learning_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('assessed_at');

            $table->string('speech', 30)->nullable()->comment('normal, gangguan-bicara');
            $table->string('speech_note', 100)->nullable();
            $table->string('daily_language', 60)->nullable();
            $table->boolean('needs_interpreter')->nullable()->comment('NULL berarti belum ditanyakan');
            $table->string('interpreter_language', 60)->nullable();
            $table->boolean('uses_sign_language')->nullable();

            // DAFTAR, bukan satu pilihan — lihat catatan kelas.
            $table->jsonb('learning_preferences')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('learning_barriers')->default(DB::raw("'[]'::jsonb"));
            $table->string('barrier_note', 150)->nullable();

            $table->string('learning_ability', 40)->nullable()
                ->comment('mampu, mampu-dengan-bantuan, tidak-mampu');
            $table->string('learning_ability_note', 150)->nullable();

            // KETIGANYA BOLEH KOSONG. Lihat catatan kelas: pertanyaan
            // keyakinan yang diwajibkan hanya menghasilkan isian
            // asal-asalan atau label yang dipaksakan.
            $table->string('illness_belief', 40)->nullable()
                ->comment('Kosakata dilebarkan dari dua pilihan Khanza, dan tidak wajib');
            $table->string('illness_belief_note', 150)->nullable();
            $table->string('decision_maker', 40)->nullable()
                ->comment('pasien-sendiri, pasangan, orang-tua, anak, keluarga-musyawarah, wali, lainnya');
            $table->string('decision_maker_note', 150)->nullable();
            $table->string('therapy_belief', 40)->nullable();
            $table->string('therapy_belief_note', 150)->nullable();

            $table->string('spiritual_need', 150)->nullable()
                ->comment('Kebutuhan pendampingan rohani bila disebutkan pasien sendiri');

            $table->unsignedBigInteger('assessed_by')->nullable();
            $table->string('assessed_by_name', 150);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'assessed_at']);
            $table->index(['patient_id', 'assessed_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".learning_assessments
            ADD CONSTRAINT learning_assessments_speech_check
            CHECK (speech IS NULL OR speech IN ('normal','gangguan-bicara'))");

        DB::statement('ALTER TABLE '.self::S.".learning_assessments
            ADD CONSTRAINT learning_assessments_ability_check
            CHECK (learning_ability IS NULL
                   OR learning_ability IN ('mampu','mampu-dengan-bantuan','tidak-mampu'))");

        DB::statement('ALTER TABLE '.self::S.".learning_assessments
            ADD CONSTRAINT learning_assessments_lists_check
            CHECK (jsonb_typeof(learning_preferences) = 'array'
                   AND jsonb_typeof(learning_barriers) = 'array')");

        // Satu pengkajian kebutuhan belajar per kunjungan; kebutuhan yang
        // berubah dicatat dengan pengkajian baru pada kunjungan berikutnya.
        DB::statement('CREATE UNIQUE INDEX learning_assessments_one_per_registration
            ON '.self::S.'.learning_assessments (registration_id)
            WHERE deleted_at IS NULL');
    }

    private function createEducationSessions(): void
    {
        Schema::create(self::S.'.education_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->string('topic', 150);
            $table->text('material');

            $table->string('given_to', 30)->comment('pasien, keluarga, pasien-dan-keluarga, lainnya');
            $table->string('given_to_note', 100)->nullable();
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_relation', 60)->nullable();

            $table->string('method', 30)->comment('ceramah, diskusi, demonstrasi, media-cetak, audio-visual');

            // Jam mulai dan selesai; TIDAK ADA kolom lama.
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();

            $table->string('verification', 30)->nullable()
                ->comment('sudah-mengerti, perlu-re-edukasi, perlu-re-demonstrasi');
            $table->string('verification_note', 200)->nullable();

            // Menunjuk edukasi yang diulangnya — bukan sekadar penanda
            // "Ulang" seperti Khanza.
            $table->unsignedBigInteger('repeats_session_id')->nullable();

            $table->unsignedBigInteger('educator_id')->nullable();
            $table->string('educator_name', 150);
            $table->string('educator_role', 60)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'started_at']);
            $table->index(['patient_id', 'started_at']);
            $table->index('repeats_session_id');
        });

        DB::statement('ALTER TABLE '.self::S.".education_sessions
            ADD CONSTRAINT education_sessions_given_to_check
            CHECK (given_to IN ('pasien','keluarga','pasien-dan-keluarga','lainnya'))");

        DB::statement('ALTER TABLE '.self::S.".education_sessions
            ADD CONSTRAINT education_sessions_method_check
            CHECK (method IN ('ceramah','diskusi','demonstrasi','media-cetak','audio-visual'))");

        DB::statement('ALTER TABLE '.self::S.".education_sessions
            ADD CONSTRAINT education_sessions_verification_check
            CHECK (verification IS NULL
                   OR verification IN ('sudah-mengerti','perlu-re-edukasi','perlu-re-demonstrasi'))");

        DB::statement('ALTER TABLE '.self::S.'.education_sessions
            ADD CONSTRAINT education_sessions_period_check
            CHECK (ended_at IS NULL OR ended_at >= started_at)');

        // Sebuah edukasi tidak bisa mengulang dirinya sendiri.
        DB::statement('ALTER TABLE '.self::S.'.education_sessions
            ADD CONSTRAINT education_sessions_self_repeat_check
            CHECK (repeats_session_id IS NULL OR repeats_session_id <> id)');

        // Satu edukasi diulang paling banyak sekali oleh satu sesi
        // berikutnya; rantai pengulangannya lurus, bukan bercabang, supaya
        // "sudah diulang atau belum" punya satu jawaban.
        DB::statement('CREATE UNIQUE INDEX education_sessions_repeat_unique
            ON '.self::S.'.education_sessions (repeats_session_id)
            WHERE repeats_session_id IS NOT NULL AND deleted_at IS NULL');
    }
};
