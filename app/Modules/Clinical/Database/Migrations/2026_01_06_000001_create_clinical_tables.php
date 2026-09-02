<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks clinical: rekam medis elektronik.
 *
 * Mengacu Permenkes 24/2022. Tiga syarat regulasi yang diterjemahkan langsung
 * menjadi bentuk tabel:
 *
 *  1. Catatan tidak boleh diubah diam-diam. Asesmen yang sudah difinalkan
 *     hanya bisa diralat lewat versi baru, dan versi lamanya disimpan utuh di
 *     assessment_revisions.
 *  2. Ada jejak siapa mencatat dan kapan. Setiap baris membawa praktisi dan
 *     waktu pencatatan, terpisah dari created_at teknis.
 *  3. Data tidak dihapus keras. Seluruh tabel klinis memakai soft delete.
 *
 * Catatan volume pada 2.000 pasien/hari: observations diperkirakan tumbuh
 * ~3,6 juta baris per tahun (enam tanda vital per kunjungan), sehingga
 * dipartisi bulanan sejak awal.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        // Tiap konteks membuat schema-nya sendiri, supaya sebuah modul bisa
        // dipasang tanpa bergantung pada migrasi bootstrap yang sudah telanjur
        // berjalan lebih dulu. Idempoten, jadi aman berdampingan dengan
        // migrasi 0000 yang menyiapkan konteks-konteks awal.
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . self::S);

        $this->createDiagnosisCodes();
        $this->createAssessments();
        $this->createAssessmentRevisions();
        $this->createObservations();
        $this->createDiagnoses();
        $this->createAllergies();
        $this->createPublishedViews();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_encounter_diagnosis');
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_patient_allergy');
        DB::statement('DROP TABLE IF EXISTS ' . self::S . '.observations CASCADE');

        Schema::dropIfExists(self::S . '.allergies');
        Schema::dropIfExists(self::S . '.diagnoses');
        Schema::dropIfExists(self::S . '.assessment_revisions');
        Schema::dropIfExists(self::S . '.assessments');
        Schema::dropIfExists(self::S . '.diagnosis_codes');
    }

    /**
     * Terminologi ICD-10.
     *
     * Kamus milik pihak luar, jadi kodenya tidak dirancang ulang — hanya
     * disimpan. Yang kita tentukan sendiri cuma cara menyimpannya.
     */
    private function createDiagnosisCodes(): void
    {
        Schema::create(self::S . '.diagnosis_codes', function (Blueprint $table) {
            $table->string('code', 12)->primary()->comment('Kode ICD-10');
            $table->string('display', 255);
            $table->string('display_id', 255)->nullable()->comment('Terjemahan bahasa Indonesia');
            $table->string('chapter', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Pencarian diagnosis dilakukan sambil mengetik, jadi butuh trigram.
        DB::statement('CREATE INDEX diagnosis_codes_display_trgm_idx
            ON ' . self::S . '.diagnosis_codes USING gin (display gin_trgm_ops)');
        DB::statement('CREATE INDEX diagnosis_codes_display_id_trgm_idx
            ON ' . self::S . '.diagnosis_codes USING gin (display_id gin_trgm_ops)');
    }

    /**
     * Asesmen dan catatan SOAP.
     */
    private function createAssessments(): void
    {
        Schema::create(self::S . '.assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Rujukan lintas konteks: id disimpan, foreign key tidak dibuat.
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            // Salinan untuk layar daftar dan pencetakan berkas.
            $table->string('registration_number', 24);
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);
            $table->string('unit_name', 150)->nullable();

            $table->string('kind', 40)->comment('asesmen-awal-keperawatan, soap-dokter, asesmen-lanjutan');

            // SOAP. Dipisah empat kolom, bukan satu blok teks, supaya bisa
            // dipetakan ke resource SATUSEHAT dan dicari per bagian.
            $table->text('subjective')->nullable()->comment('S — keluhan dan riwayat');
            $table->text('objective')->nullable()->comment('O — pemeriksaan fisik');
            $table->text('assessment')->nullable()->comment('A — penilaian klinis');
            $table->text('plan')->nullable()->comment('P — rencana tata laksana');

            $table->text('chief_complaint')->nullable()->comment('Keluhan utama, dicatat terpisah untuk triase dan laporan');

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();

            // Waktu pencatatan klinis, terpisah dari created_at teknis.
            $table->timestampTz('recorded_at');

            /*
             * draft  : masih bisa disunting di tempat
             * final  : terkunci; perubahan berikutnya membuat versi baru
             * amended: pernah diralat setelah difinalkan
             */
            $table->string('status', 12)->default('draft');
            $table->unsignedSmallInteger('version')->default(1);

            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'kind']);
            $table->index(['patient_id', 'recorded_at']);
            $table->index('practitioner_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".assessments ADD CONSTRAINT assessments_status_check
            CHECK (status IN ('draft','final','amended'))");

        DB::statement("ALTER TABLE " . self::S . ".assessments ADD CONSTRAINT assessments_kind_check
            CHECK (kind IN ('asesmen-awal-keperawatan','soap-dokter','asesmen-lanjutan'))");

        // Satu kunjungan hanya boleh punya satu asesmen aktif per jenis.
        DB::statement('CREATE UNIQUE INDEX assessments_active_per_kind
            ON ' . self::S . '.assessments (registration_id, kind)
            WHERE deleted_at IS NULL');
    }

    /**
     * Riwayat perubahan asesmen.
     *
     * Inilah yang membuat rekam medis elektronik memenuhi syarat jejak audit
     * Permenkes 24/2022: isi versi sebelumnya disimpan utuh, bukan ditimpa.
     * Tabel ini hanya menerima INSERT — tidak ada jalur UPDATE atau DELETE.
     */
    private function createAssessmentRevisions(): void
    {
        Schema::create(self::S . '.assessment_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('assessment_id')->constrained(self::S . '.assessments')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');

            $table->jsonb('content')->comment('Isi lengkap asesmen pada versi tersebut');
            $table->string('reason', 255)->comment('Alasan ralat, wajib diisi');

            $table->unsignedBigInteger('revised_by')->nullable();
            $table->string('revised_by_name', 150)->nullable();
            $table->timestampTz('revised_at');

            $table->unique(['assessment_id', 'version']);
        });
    }

    /**
     * Tanda vital dan pengukuran.
     *
     * Disimpan sebagai baris per pengukuran, bukan kolom per jenis. Tiga
     * alasannya: jenis pengukuran baru tidak menuntut perubahan struktur,
     * bentuknya sejalan dengan resource Observation SATUSEHAT, dan tabel tetap
     * ramping saat dipartisi.
     */
    private function createObservations(): void
    {
        DB::statement('
            CREATE TABLE ' . self::S . '.observations (
                id               bigint GENERATED ALWAYS AS IDENTITY,
                observed_at      timestamptz  NOT NULL DEFAULT now(),
                registration_id  bigint       NOT NULL,
                patient_id       bigint       NOT NULL,
                assessment_id    bigint       NULL,
                code             varchar(40)  NOT NULL,
                display          varchar(120) NOT NULL,
                value_numeric    numeric(10,2) NULL,
                value_text       varchar(255) NULL,
                unit             varchar(20)  NULL,
                is_abnormal      boolean      NOT NULL DEFAULT false,
                practitioner_id  bigint       NULL,
                created_by       bigint       NULL,
                created_at       timestamptz  NOT NULL DEFAULT now(),
                PRIMARY KEY (id, observed_at)
            ) PARTITION BY RANGE (observed_at)
        ');

        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = 0; $i < 24; $i++) {
            $from = $start->modify("+{$i} months");
            $to = $from->modify('+1 month');

            DB::statement(sprintf(
                'CREATE TABLE %s.observations_%s PARTITION OF %s.observations FOR VALUES FROM (%s) TO (%s)',
                self::S,
                $from->format('Y_m'),
                self::S,
                "'" . $from->format('Y-m-d') . "'",
                "'" . $to->format('Y-m-d') . "'"
            ));
        }

        DB::statement(
            'CREATE TABLE ' . self::S . '.observations_default PARTITION OF '
            . self::S . '.observations DEFAULT'
        );

        DB::statement('CREATE INDEX observations_registration_idx
            ON ' . self::S . '.observations (registration_id, code)');
        DB::statement('CREATE INDEX observations_patient_trend_idx
            ON ' . self::S . '.observations (patient_id, code, observed_at DESC)');
    }

    private function createDiagnoses(): void
    {
        Schema::create(self::S . '.diagnoses', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 24);

            $table->string('code', 12);
            $table->string('display', 255)->comment('Disalin saat pencatatan: kamus ICD bisa berubah, diagnosis yang tercatat tidak boleh ikut berubah');

            $table->string('rank', 20)->default('sekunder')->comment('utama, sekunder, komplikasi');
            $table->string('certainty', 20)->default('kerja')->comment('suspek, kerja, definitif');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();
            $table->timestampTz('diagnosed_at');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'rank']);
            $table->index(['patient_id', 'diagnosed_at']);
            $table->index('code');
        });

        DB::statement("ALTER TABLE " . self::S . ".diagnoses ADD CONSTRAINT diagnoses_rank_check
            CHECK (rank IN ('utama','sekunder','komplikasi'))");
        DB::statement("ALTER TABLE " . self::S . ".diagnoses ADD CONSTRAINT diagnoses_certainty_check
            CHECK (certainty IN ('suspek','kerja','definitif'))");

        // Satu kunjungan hanya boleh punya satu diagnosis utama.
        DB::statement("CREATE UNIQUE INDEX diagnoses_single_primary
            ON " . self::S . ".diagnoses (registration_id)
            WHERE rank = 'utama' AND deleted_at IS NULL");
    }

    /**
     * Alergi melekat pada pasien, bukan pada kunjungan.
     */
    private function createAllergies(): void
    {
        Schema::create(self::S . '.allergies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('recorded_in_registration_id')->nullable();

            $table->string('substance', 150);
            $table->string('category', 20)->default('obat')->comment('obat, makanan, lingkungan, lainnya');
            $table->string('reaction', 255)->nullable();
            $table->string('severity', 20)->default('sedang')->comment('ringan, sedang, berat');
            $table->string('status', 20)->default('aktif')->comment('aktif, tidak-aktif, disangkal');

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".allergies ADD CONSTRAINT allergies_category_check
            CHECK (category IN ('obat','makanan','lingkungan','lainnya'))");
        DB::statement("ALTER TABLE " . self::S . ".allergies ADD CONSTRAINT allergies_severity_check
            CHECK (severity IN ('ringan','sedang','berat'))");
        DB::statement("ALTER TABLE " . self::S . ".allergies ADD CONSTRAINT allergies_status_check
            CHECK (status IN ('aktif','tidak-aktif','disangkal'))");

        // Zat yang sama tidak dicatat dua kali sebagai alergi aktif.
        DB::statement("CREATE UNIQUE INDEX allergies_active_substance
            ON " . self::S . ".allergies (patient_id, lower(substance))
            WHERE status = 'aktif' AND deleted_at IS NULL");
    }

    /**
     * Kontrak baca untuk konteks lain.
     */
    private function createPublishedViews(): void
    {
        // pharmacy memakai ini untuk telaah resep.
        DB::statement("CREATE VIEW " . self::S . ".v_patient_allergy AS
            SELECT patient_id, substance, category, reaction, severity, recorded_at
            FROM " . self::S . ".allergies
            WHERE status = 'aktif' AND deleted_at IS NULL");

        // billing memakai ini untuk pengajuan klaim dan grouping INACBG.
        DB::statement("CREATE VIEW " . self::S . ".v_encounter_diagnosis AS
            SELECT registration_id, registration_number, patient_id,
                   code, display, rank, certainty, diagnosed_at
            FROM " . self::S . ".diagnoses
            WHERE deleted_at IS NULL");
    }
};
