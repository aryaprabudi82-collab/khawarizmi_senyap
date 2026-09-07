<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restrain (domain M item T).
 *
 * Menaungi pengkajian_restrain.
 *
 * RESTRAIN ADALAH TINDAKAN YANG PALING MEMBATASI HAK PASIEN yang boleh
 * dilakukan rumah sakit, dan justru di situ catatan Khanza paling
 * kurang. pengkajian_restrain hanya punya satu baris penilaian berikut
 * tanda vital, tanpa empat hal yang menjadi pengaman pokoknya:
 *
 * 1. TIDAK ADA PERINTAH DOKTER. Yang tercatat cuma nip perawat yang
 *    mengisi. Restrain tanpa perintah dokter adalah pengekangan tanpa
 *    dasar, dan catatan yang tidak menyebut siapa yang memerintahkan
 *    membuat tanggung jawabnya jatuh ke perawat yang memasangnya.
 *
 * 2. TIDAK ADA JAM MULAI DAN JAM LEPAS. Yang ada hanya `tanggal`
 *    penilaian. Berapa lama pasien terikat — pertanyaan pokok setiap
 *    peninjauan restrain — tidak bisa dijawab sama sekali.
 *
 * 3. TIDAK ADA PENILAIAN ULANG. Restrain menuntut pemeriksaan berkala
 *    atas sirkulasi, kulit, dan apakah pengekangannya masih perlu.
 *    Baris penilaian Khanza boleh berulang, tapi tidak ada yang
 *    menautkannya ke episode yang sama, jadi tidak bisa dijawab apakah
 *    seorang pasien pernah ditinjau ulang.
 *
 * 4. TIDAK ADA CATATAN UPAYA YANG LEBIH RINGAN. Restrain adalah pilihan
 *    terakhir; yang harus dibuktikan justru bahwa cara lain sudah
 *    dicoba dan gagal. Di sini upaya itu WAJIB dicatat — dan bila
 *    memang tidak ada yang dicoba, itulah temuan yang harus terlihat.
 *
 * Maka bentuknya di sini EPISODE, bukan baris penilaian: satu
 * pemasangan restrain dengan awal, akhir, perintah yang mendasarinya,
 * dan penilaian ulang yang menempel padanya.
 *
 * JENIS RESTRAIN ADALAH DAFTAR. restrain_non_farmakologi Khanza enum
 * satu pilihan — pasien yang diikat kedua pergelangan tangan sekaligus
 * kedua pergelangan kaki hanya bisa mencatat salah satunya, padahal
 * jumlah titik pengekangan itulah yang menentukan seberapa berat
 * pembatasannya.
 *
 * MASA BERLAKU PERINTAH DAN TENGGAT PENILAIAN ULANG MENAGIH, TIDAK
 * MELARANG. Melepas pasien yang masih berbahaya karena perintahnya
 * kedaluwarsa jelas lebih berbahaya daripada perintah yang telat
 * diperbarui. Yang ditegakkan: keduanya bisa disebutkan, sehingga
 * yang telat ketahuan dan bisa ditagih.
 *
 * TANDA VITAL TIDAK DIDUPLIKASI — panel observasi sejak item D.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createEpisodes();
        $this->createReviews();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.restraint_reviews');
        Schema::dropIfExists(self::S.'.restraint_episodes');
    }

    private function createEpisodes(): void
    {
        Schema::create(self::S.'.restraint_episodes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            // PERINTAH DOKTER — tidak ada di Khanza.
            $table->unsignedBigInteger('ordered_by')->nullable();
            $table->string('ordered_by_name', 150);
            $table->timestampTz('ordered_at');
            $table->timestampTz('order_expires_at')
                ->comment('Masa berlaku perintah; dihitung saat pemasangan, ditagih bukan dilarang');

            $table->string('indication', 40)
                ->comment('membahayakan-diri, membahayakan-orang-lain, mengganggu-terapi-penting');
            $table->text('indication_note')->nullable();
            $table->text('observed_behaviour');

            // WAJIB TIDAK KOSONG — restrain adalah pilihan terakhir.
            $table->jsonb('alternatives_tried')->default(DB::raw("'[]'::jsonb"));
            $table->text('alternatives_note')->nullable();

            // DAFTAR, bukan satu pilihan.
            $table->jsonb('restraint_types')->default(DB::raw("'[]'::jsonb"));
            $table->string('restraint_types_note', 150)->nullable();
            $table->text('pharmacological_restraint')->nullable();

            $table->boolean('family_informed')->default(false);
            $table->string('consenting_family_name', 150)->nullable();
            $table->string('consenting_family_relation', 60)->nullable();

            // JAM MULAI DAN JAM LEPAS — tidak ada di Khanza.
            $table->timestampTz('started_at');
            $table->timestampTz('released_at')->nullable();
            $table->text('release_reason')->nullable();

            $table->string('status', 20)->default('berjalan')
                ->comment('berjalan, dilepas, dibatalkan');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'started_at']);
            $table->index(['patient_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".restraint_episodes
            ADD CONSTRAINT restraint_episodes_status_check
            CHECK (status IN ('berjalan','dilepas','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".restraint_episodes
            ADD CONSTRAINT restraint_episodes_indication_check
            CHECK (indication IN ('membahayakan-diri','membahayakan-orang-lain','mengganggu-terapi-penting'))");

        DB::statement('ALTER TABLE '.self::S.".restraint_episodes
            ADD CONSTRAINT restraint_episodes_lists_check
            CHECK (jsonb_typeof(alternatives_tried) = 'array'
                   AND jsonb_typeof(restraint_types) = 'array')");

        // Restrain tanpa satu pun titik pengekangan bukan restrain.
        DB::statement('ALTER TABLE '.self::S.'.restraint_episodes
            ADD CONSTRAINT restraint_episodes_types_check
            CHECK (jsonb_array_length(restraint_types) > 0
                   OR (pharmacological_restraint IS NOT NULL
                       AND btrim(pharmacological_restraint) <> \'\'))');

        DB::statement('ALTER TABLE '.self::S.'.restraint_episodes
            ADD CONSTRAINT restraint_episodes_period_check
            CHECK (released_at IS NULL OR released_at >= started_at)');

        DB::statement('ALTER TABLE '.self::S.'.restraint_episodes
            ADD CONSTRAINT restraint_episodes_order_before_start_check
            CHECK (started_at >= ordered_at)');

        // Pelepasan wajib menyebut alasannya: kapan dan mengapa restrain
        // dihentikan adalah bagian dari pembenarannya.
        DB::statement('ALTER TABLE '.self::S.".restraint_episodes
            ADD CONSTRAINT restraint_episodes_release_check
            CHECK (status <> 'dilepas'
                   OR (released_at IS NOT NULL
                       AND release_reason IS NOT NULL AND btrim(release_reason) <> ''))");

        // Satu pasien tidak bisa punya dua episode restrain berjalan pada
        // kunjungan yang sama: yang kedua sebenarnya penambahan titik
        // pengekangan pada episode yang sama.
        DB::statement('CREATE UNIQUE INDEX restraint_episodes_one_running
            ON '.self::S.".restraint_episodes (registration_id)
            WHERE status = 'berjalan' AND deleted_at IS NULL");
    }

    private function createReviews(): void
    {
        Schema::create(self::S.'.restraint_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('episode_id');
            $table->timestampTz('reviewed_at');

            // Yang diperiksa pada tiap peninjauan restrain.
            $table->string('circulation', 30)->nullable()->comment('baik, menurun, tidak-teraba');
            $table->string('skin_condition', 30)->nullable()->comment('utuh, kemerahan, lecet, luka');
            $table->boolean('position_changed')->nullable();
            $table->boolean('basic_needs_met')->nullable()
                ->comment('Minum, makan, dan eliminasi selama terikat');

            // Keputusan pokok tiap peninjauan.
            $table->boolean('still_needed');
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name', 150);

            $table->timestampsTz();

            $table->index(['episode_id', 'reviewed_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".restraint_reviews
            ADD CONSTRAINT restraint_reviews_circulation_check
            CHECK (circulation IS NULL OR circulation IN ('baik','menurun','tidak-teraba'))");

        DB::statement('ALTER TABLE '.self::S.".restraint_reviews
            ADD CONSTRAINT restraint_reviews_skin_check
            CHECK (skin_condition IS NULL
                   OR skin_condition IN ('utuh','kemerahan','lecet','luka'))");

        // Meneruskan pengekangan wajib beralasan; menghentikannya tidak.
        // Bebannya sengaja tidak simetris — yang perlu dibenarkan adalah
        // membatasi orang, bukan melepaskannya.
        DB::statement('ALTER TABLE '.self::S.".restraint_reviews
            ADD CONSTRAINT restraint_reviews_reason_check
            CHECK (still_needed IS NOT TRUE
                   OR (reason IS NOT NULL AND btrim(reason) <> ''))");

        DB::statement('CREATE UNIQUE INDEX restraint_reviews_time_unique
            ON '.self::S.'.restraint_reviews (episode_id, reviewed_at)');
    }
};
