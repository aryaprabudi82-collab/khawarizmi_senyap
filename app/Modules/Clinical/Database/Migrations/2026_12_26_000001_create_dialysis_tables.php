<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hemodialisa (domain M item P).
 *
 * Menaungi hemodialisa, catatan_observasi_hemodialisa, dan
 * catatan_cairan_hemodialisa.
 *
 * BALANS CAIRANNYA TIDAK DIBUATKAN TABEL KEDUA, dan itu sudah disiapkan
 * sejak item E: catalog.fluid_items memuat sisa-priming, wash-out, dan
 * ultrafiltrasi dengan care_context 'hemodialisa', sehingga cairan HD
 * dicatat lewat clinical.fluid_balance_entries yang sama dengan bangsal.
 * catatan_cairan_hemodialisa Khanza adalah tabel balans KEDUA dengan
 * kolom per jenis cairannya sendiri — akibatnya balans bangsal dan
 * balans ruang HD dihitung kode yang berbeda, dan untuk pasien yang sama
 * pada hari yang sama keduanya tidak bisa dijumlahkan.
 *
 * PARAMETER MESIN BUKAN TANDA VITAL PASIEN, dan pemisahan itu yang
 * menentukan bentuk item ini. catatan_observasi_hemodialisa mencampur
 * keduanya: qb, qd, tekanan arteri, tekanan vena, TMP, dan UFR adalah
 * ukuran MESIN; tensi, nadi, suhu, dan SpO2 adalah ukuran PASIEN. Yang
 * kedua sudah punya rumahnya sejak item D (panel observasi), dan
 * menyalinnya ke sini melahirkan dua tekanan darah yang bisa berbeda
 * pada menit yang sama. Maka tabel ini hanya memuat ukuran mesin.
 *
 * LAMA DIALISIS DIHITUNG, TIDAK DIKETIK. hemodialisa.lama varchar(5)
 * berdiri sendiri tanpa jam mulai maupun jam selesai — jadi durasinya
 * diketik, bukan diturunkan. Kecukupan dialisis bergantung pada
 * tercapainya durasi yang diresepkan, dan durasi yang diketik cenderung
 * terisi sesuai resep alih-alih sesuai kenyataan. Di sini yang dicatat
 * jam mulai dan jam selesai; lamanya diturunkan, dan selisih terhadap
 * durasi yang diresepkan bisa disebutkan.
 *
 * SEROLOGI PINDAH KE TINGKAT PASIEN. hemodialisa Khanza menyimpan
 * hbsag, hiv, dan hcv pada SETIAP sesi. Pasien yang cuci darah dua kali
 * seminggu selama tiga tahun meninggalkan lebih dari tiga ratus salinan
 * yang bisa saling bertentangan — sementara keputusan yang bergantung
 * padanya berat: pasien HBsAg positif wajib memakai mesin terpisah.
 * Di sini serologi jadi catatan tingkat pasien berikut masa berlakunya,
 * dan yang dibaca saat menjadwalkan mesin adalah satu baris yang masih
 * berlaku.
 *
 * TARGET ULTRAFILTRASI DISIMPAN MESKI BISA DIHITUNG, dan itu disengaja
 * — berbeda dari nilai turunan lain yang dilarang. Berat sebelum
 * dikurangi berat kering memang menghasilkan angka, tapi target yang
 * DIRESEPKAN dokter boleh berbeda darinya: pasien yang minum selama
 * sesi, atau yang tidak tahan penarikan sebanyak itu, ditarik lebih
 * sedikit dengan sengaja. Yang disimpan adalah keputusan klinis;
 * selisihnya terhadap hitungan aritmetik justru yang perlu terlihat.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createSerologies();
        $this->createSessions();
        $this->createObservations();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.dialysis_observations');
        Schema::dropIfExists(self::S.'.dialysis_sessions');
        Schema::dropIfExists(self::S.'.dialysis_serologies');
    }

    private function createSerologies(): void
    {
        Schema::create(self::S.'.dialysis_serologies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->date('tested_on');
            $table->string('hbsag', 20)->comment('reaktif, non-reaktif, belum-diperiksa');
            $table->string('anti_hcv', 20);
            $table->string('anti_hiv', 20);

            $table->string('laboratory', 150)->nullable();
            $table->string('reference_number', 60)->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'tested_on']);
        });

        foreach (['hbsag', 'anti_hcv', 'anti_hiv'] as $kolom) {
            DB::statement('ALTER TABLE '.self::S.".dialysis_serologies
                ADD CONSTRAINT dialysis_serologies_{$kolom}_check
                CHECK ({$kolom} IN ('reaktif','non-reaktif','belum-diperiksa'))");
        }

        // Satu pemeriksaan per pasien per tanggal; pemeriksaan ulang di
        // tanggal yang sama adalah koreksi, bukan hasil kedua.
        DB::statement('CREATE UNIQUE INDEX dialysis_serologies_patient_date
            ON '.self::S.'.dialysis_serologies (patient_id, tested_on)
            WHERE deleted_at IS NULL');
    }

    private function createSessions(): void
    {
        Schema::create(self::S.'.dialysis_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();

            // Jam mulai dan selesai; TIDAK ADA kolom lama.
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedSmallInteger('prescribed_minutes')->nullable()
                ->comment('Durasi yang diresepkan; yang tercapai dihitung dari kedua waktu di atas');

            $table->string('access_type', 40)->nullable()
                ->comment('av-fistula, av-graft, kateter-double-lumen, kateter-tunneled');
            $table->string('access_site', 60)->nullable();
            $table->string('dialyser', 60)->nullable();
            $table->unsignedSmallInteger('dialyser_reuse_count')->nullable()
                ->comment('Pemakaian ke berapa; dializer pakai ulang punya batas yang harus terlihat');
            $table->string('machine_code', 40)->nullable();
            $table->string('dialysate', 60)->nullable();

            $table->unsignedSmallInteger('blood_flow_ml_min')->nullable()->comment('Qb');
            $table->unsignedSmallInteger('dialysate_flow_ml_min')->nullable()->comment('Qd');

            $table->decimal('dry_weight_kg', 5, 1)->nullable();
            $table->decimal('pre_weight_kg', 5, 1)->nullable();
            $table->decimal('post_weight_kg', 5, 1)->nullable();
            $table->decimal('target_ultrafiltration_l', 4, 2)->nullable()
                ->comment('Yang DIRESEPKAN, boleh berbeda dari selisih berat — lihat catatan kelas');
            $table->decimal('achieved_ultrafiltration_l', 4, 2)->nullable();

            $table->string('anticoagulant', 60)->nullable();
            $table->string('anticoagulant_dose', 60)->nullable();

            $table->text('complications')->nullable();
            $table->text('note')->nullable();

            $table->string('status', 20)->default('berjalan')
                ->comment('berjalan, selesai, dihentikan, dibatalkan');
            $table->string('termination_reason', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index('registration_id');
        });

        DB::statement('ALTER TABLE '.self::S.".dialysis_sessions
            ADD CONSTRAINT dialysis_sessions_status_check
            CHECK (status IN ('berjalan','selesai','dihentikan','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".dialysis_sessions
            ADD CONSTRAINT dialysis_sessions_access_check
            CHECK (access_type IS NULL
                   OR access_type IN ('av-fistula','av-graft','kateter-double-lumen','kateter-tunneled'))");

        DB::statement('ALTER TABLE '.self::S.'.dialysis_sessions
            ADD CONSTRAINT dialysis_sessions_period_check
            CHECK (ended_at IS NULL OR ended_at >= started_at)');

        // Sesi yang dihentikan di tengah harus menyebut alasannya: itulah
        // yang ditinjau saat kecukupan dialisis seorang pasien meleset
        // berulang kali.
        DB::statement('ALTER TABLE '.self::S.".dialysis_sessions
            ADD CONSTRAINT dialysis_sessions_terminated_check
            CHECK (status <> 'dihentikan'
                   OR (termination_reason IS NOT NULL AND btrim(termination_reason) <> ''))");

        DB::statement('ALTER TABLE '.self::S.".dialysis_sessions
            ADD CONSTRAINT dialysis_sessions_finished_check
            CHECK (status <> 'selesai' OR ended_at IS NOT NULL)");

        // Satu kunjungan satu sesi: kunjungan HD memang satu sesi, dan
        // sesi berikutnya adalah kunjungan berikutnya.
        DB::statement('CREATE UNIQUE INDEX dialysis_sessions_one_per_registration
            ON '.self::S.".dialysis_sessions (registration_id)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    private function createObservations(): void
    {
        Schema::create(self::S.'.dialysis_observations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('session_id');
            $table->timestampTz('observed_at');

            // HANYA UKURAN MESIN. Tensi, nadi, suhu, dan SpO2 pasien masuk
            // panel observasi sejak item D.
            $table->unsignedSmallInteger('blood_flow_ml_min')->nullable()->comment('Qb');
            $table->unsignedSmallInteger('dialysate_flow_ml_min')->nullable()->comment('Qd');
            $table->smallInteger('arterial_pressure_mmhg')->nullable()
                ->comment('Boleh negatif — tekanan arteri pada sisi hisap memang bernilai minus');
            $table->smallInteger('venous_pressure_mmhg')->nullable();
            $table->smallInteger('transmembrane_pressure_mmhg')->nullable()->comment('TMP');
            $table->unsignedSmallInteger('ultrafiltration_rate_ml_h')->nullable()->comment('UFR');
            $table->decimal('ultrafiltration_goal_l', 4, 2)->nullable()->comment('UFG');

            $table->string('action_taken', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();

            $table->index(['session_id', 'observed_at']);
        });

        // Satu pencatatan per menit pengamatan; dua baris pada waktu yang
        // sama berarti dua pembacaan mesin yang bisa berbeda.
        DB::statement('CREATE UNIQUE INDEX dialysis_observations_time
            ON '.self::S.'.dialysis_observations (session_id, observed_at)');
    }
};
