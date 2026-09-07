<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asuhan gizi (domain M item K).
 *
 * Menaungi asuhan_gizi, catatan_adime_gizi, dan monitoring_asuhan_gizi.
 * skrining_gizi sudah tertutup sejak awal oleh clinical.screenings
 * (kolom nutrition_at_risk) dan tidak dibangun ulang di sini.
 *
 * TUJUH KOLOM ALERGEN TETAP. asuhan_gizi Khanza punya alergi_telur,
 * alergi_susu_sapi, alergi_kacang, alergi_gluten, alergi_udang,
 * alergi_ikan, dan alergi_hazelnut — masing-masing enum('Ya','Tidak').
 * Alergen kedelapan tidak punya tempat; pasien yang alergi kedelai atau
 * wijen tidak bisa dicatat. Padahal clinical.allergies sudah ada sejak
 * awal dengan category 'makanan', melekat pada PASIEN, dan sudah dibaca
 * telaah resep serta resume medis.
 *
 * Maka asuhan gizi MEMBACA DAN MENULIS daftar alergi pasien, sama
 * seperti rekonsiliasi obat pada item I. Yang dibekukan di sini hanya
 * salinan keadaan saat asuhan disusun.
 *
 * ANTROPOMETRI TERSIMPAN SEBAGAI ANGKA. Khanza memakai char(5) untuk
 * berat, tinggi, LLA, dan tinggi lutut. Ukuran yang berupa teks tidak
 * bisa ditrenkan, tidak bisa dijadikan grafik, dan tidak bisa
 * dibandingkan dengan kurva pertumbuhan — padahal justru perubahan
 * berat dari waktu ke waktu yang jadi inti pemantauan gizi.
 *
 * ENAM INDEKS TURUNAN TIDAK DISIMPAN. asuhan_gizi menyimpan
 * antropometri_imt, _bbideal, _bbperu, _tbperu, _bbpertb, dan _llaperu
 * — keenamnya bisa dihitung dari berat, tinggi, umur, dan jenis
 * kelamin yang semuanya sudah tercatat. Enam angka tersimpan berarti
 * enam angka yang bisa berbeda dari bahan yang melahirkannya.
 *
 * DAN INI YANG PERLU DISEBUT TERANG-TERANGAN: dari keenamnya, hanya
 * IMT dan berat badan ideal dewasa yang punya rumus baku dan dihitung
 * di sini. BB/U, TB/U, BB/TB, dan LLA/U anak adalah z-score terhadap
 * TABEL STANDAR PERTUMBUHAN WHO, dan tabel itu tidak ada di repositori
 * ini. Mengarang nilainya akan menghasilkan angka yang tampak resmi
 * tapi salah, pada penilaian yang justru menentukan apakah seorang anak
 * dinyatakan gizi buruk. Maka pengukurannya disimpan, indeksnya
 * MENUNGGU IMPOR TABEL WHO — pekerjaan data, bukan pekerjaan kode.
 *
 * ADIME JADI SATU BENTUK, BUKAN TIGA TABEL. catatan_adime_gizi punya
 * asesmen/diagnosis/intervensi/monitoring/evaluasi/instruksi;
 * monitoring_asuhan_gizi punya monitoring dan evaluasi saja — dua huruf
 * terakhir dari ADIME yang sama. Di sini keduanya satu tabel catatan
 * dengan kolom yang boleh kosong, karena catatan pemantauan memang
 * hanya mengisi sebagian ADIME.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createAssessments();
        $this->createNotes();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.nutrition_notes');
        Schema::dropIfExists(self::S.'.nutrition_assessments');
    }

    private function createAssessments(): void
    {
        Schema::create(self::S.'.nutrition_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->date('assessed_on');

            // Umur dan jenis kelamin DISALIN saat asuhan disusun: indeks
            // antropometri anak bergantung pada umur pada hari pengukuran,
            // dan umur yang dihitung ulang bertahun kemudian menghasilkan
            // indeks yang berbeda dari yang dibaca ahli gizinya waktu itu.
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->string('sex', 1)->nullable();

            // ANGKA, bukan char(5) — lihat catatan kelas.
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('mid_upper_arm_cm', 5, 1)->nullable()->comment('LLA');
            $table->decimal('knee_height_cm', 5, 1)->nullable()->comment('Tinggi lutut, dipakai bila pasien tidak bisa berdiri');
            $table->decimal('ulna_length_cm', 5, 1)->nullable();

            // TIDAK ADA KOLOM IMT, BB IDEAL, BB/U, TB/U, BB/TB, LLA/U.
            // Lihat catatan kelas: yang berumus baku dihitung, yang butuh
            // tabel WHO menunggu impor tabelnya.

            $table->text('biochemistry')->nullable()->comment('biokimia');
            $table->text('physical_clinical')->nullable()->comment('fisik_klinis');
            $table->text('eating_pattern')->nullable()->comment('pola_makan');
            $table->text('personal_history')->nullable()->comment('riwayat_personal');

            // Salinan alergi makanan saat asuhan disusun. Daftar yang hidup
            // tetap clinical.allergies.
            $table->jsonb('food_allergies')->default(DB::raw("'[]'::jsonb"));

            $table->text('nutrition_diagnosis')->nullable();
            $table->text('intervention')->nullable();
            $table->text('monitoring_plan')->nullable();

            $table->unsignedBigInteger('dietitian_id')->nullable();
            $table->string('dietitian_name', 150)->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'assessed_on']);
            $table->index(['status', 'assessed_on']);
        });

        DB::statement('ALTER TABLE '.self::S.".nutrition_assessments
            ADD CONSTRAINT nutrition_assessments_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".nutrition_assessments
            ADD CONSTRAINT nutrition_assessments_sex_check
            CHECK (sex IS NULL OR sex IN ('L','P'))");

        // Ukuran yang mustahil menandakan salah ketik, dan salah ketik pada
        // antropometri berujung pada diagnosis gizi yang salah.
        DB::statement('ALTER TABLE '.self::S.'.nutrition_assessments
            ADD CONSTRAINT nutrition_assessments_measure_check
            CHECK ((weight_kg IS NULL OR (weight_kg > 0 AND weight_kg < 400))
                   AND (height_cm IS NULL OR (height_cm > 0 AND height_cm < 260)))');

        DB::statement('ALTER TABLE '.self::S.".nutrition_assessments
            ADD CONSTRAINT nutrition_assessments_final_check
            CHECK (status <> 'final'
                   OR (finalized_at IS NOT NULL
                       AND dietitian_name IS NOT NULL
                       AND nutrition_diagnosis IS NOT NULL
                       AND btrim(nutrition_diagnosis) <> ''))");

        // Satu asuhan gizi per kunjungan per hari — asuhan_gizi Khanza pun
        // berkunci (no_rawat, tanggal). Pemantauan hariannya masuk
        // nutrition_notes, bukan asuhan baru.
        DB::statement('CREATE UNIQUE INDEX nutrition_assessments_one_per_day
            ON '.self::S.".nutrition_assessments (registration_id, assessed_on)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    private function createNotes(): void
    {
        Schema::create(self::S.'.nutrition_notes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('assessment_id')->nullable()
                ->comment('Asuhan yang dipantau catatan ini; boleh kosong untuk catatan lepas');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('noted_at');
            $table->string('kind', 20)->default('adime')->comment('adime atau monitoring');

            // Kelima huruf ADIME plus instruksi. Boleh kosong sebagian:
            // catatan pemantauan memang hanya mengisi M dan E.
            $table->text('assessment')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('intervention')->nullable();
            $table->text('monitoring')->nullable();
            $table->text('evaluation')->nullable();
            $table->text('instruction')->nullable();

            $table->unsignedBigInteger('dietitian_id')->nullable();
            $table->string('dietitian_name', 150)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'noted_at']);
            $table->index(['patient_id', 'noted_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".nutrition_notes
            ADD CONSTRAINT nutrition_notes_kind_check
            CHECK (kind IN ('adime','monitoring'))");

        // Catatan yang seluruh bagiannya kosong bukan catatan. Khanza
        // membiarkan keenam kolomnya NULL sekaligus, sehingga baris kosong
        // bisa tercipta dan terhitung sebagai kunjungan ahli gizi.
        DB::statement('ALTER TABLE '.self::S.".nutrition_notes
            ADD CONSTRAINT nutrition_notes_not_empty_check
            CHECK (COALESCE(btrim(assessment), '') <> ''
                   OR COALESCE(btrim(diagnosis), '') <> ''
                   OR COALESCE(btrim(intervention), '') <> ''
                   OR COALESCE(btrim(monitoring), '') <> ''
                   OR COALESCE(btrim(evaluation), '') <> ''
                   OR COALESCE(btrim(instruction), '') <> '')");
    }
};
