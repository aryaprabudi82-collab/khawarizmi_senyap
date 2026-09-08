<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register program TB (domain O item E).
 *
 * Menaungi 11 kode grafik_tb_* domain O, dan memberi isi pada
 * kemenkes_sitt domain J yang sebelumnya baru menghitung DIAGNOSIS TB
 * dari kamus ICD — bukan register programnya.
 *
 * INI DIBANGUN, BERBEDA DARI LIMA SUMBU KEPEGAWAIAN PADA ITEM D YANG
 * DITOLAK, dan bedanya perlu disebut supaya keduanya tidak terbaca
 * sebagai keputusan yang tidak konsisten.
 *
 * Kosakata kepegawaian — berapa jenjang jabatan, siapa masuk kelompok
 * mana — adalah diskresi RSP UI, dan menebaknya berarti mengarang
 * struktur sebuah rumah sakit. Kosakata register TB TIDAK: klasifikasi
 * tipe diagnosis, lokasi anatomi, riwayat pengobatan, status HIV, dan
 * hasil akhir pengobatan semuanya DITETAPKAN PROGRAM TB NASIONAL, dan
 * enum Khanza cuma menyalinnya. Menyalin daftar yang sudah resmi bukan
 * mengarang.
 *
 * SATU ATURAN YANG KHANZA SENDIRI TIDAK TEGAKKAN, DAN INILAH INTINYA:
 * "Sembuh" dan "Pengobatan lengkap" BUKAN dua nama untuk hal yang sama.
 * Keduanya sama-sama berarti pengobatan selesai; yang membedakan cuma
 * satu hal — sembuh menuntut BUKTI BAKTERIOLOGIS NEGATIF pada akhir
 * pengobatan, pengobatan lengkap tidak punya buktinya. Angka kesembuhan
 * TB yang dilaporkan ke program nasional dihitung dari yang pertama
 * saja, jadi menandai pasien "sembuh" tanpa pemeriksaan akhir yang
 * negatif melebih-lebihkan keberhasilan program — dan yang dirugikan
 * bukan rumah sakitnya melainkan perencanaan pengendalian TB nasional
 * yang bersandar pada angka itu.
 *
 * Ditegakkan service DAN basis data.
 *
 * SKORING ANAK HANYA UNTUK ANAK. Sistem skoring TB anak dipakai ketika
 * konfirmasi bakteriologis sulit didapat pada anak; memasangnya pada
 * dewasa menghasilkan angka yang tidak berarti dan menutupi bahwa
 * pemeriksaan dahak yang seharusnya dikerjakan tidak dikerjakan.
 *
 * STATUS HIV "TIDAK DIKETAHUI" ADALAH JAWABAN YANG SAH dan tidak boleh
 * dipaksa jadi negatif: pasien TB yang belum dites HIV berbeda dari
 * pasien TB yang hasilnya non-reaktif, dan justru yang pertama yang
 * harus ditawari tes.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createCases();
        $this->createFollowups();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.tb_followups');
        Schema::dropIfExists(self::S.'.tb_cases');
    }

    private function createCases(): void
    {
        Schema::create(self::S.'.tb_cases', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('register_number', 30)->unique()->comment('Nomor register TB-03');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->unsignedSmallInteger('age_years')->nullable()
                ->comment('Disalin saat pendaftaran kasus: skoring anak bergantung umur saat itu');

            $table->date('registered_on');
            $table->unsignedTinyInteger('report_quarter')->comment('1-4, triwulan laporan');
            $table->unsignedSmallInteger('report_year');

            $table->string('referral_source', 40)->nullable()
                ->comment('inisiatif-sendiri, kader, faskes, dokter-praktik-mandiri, poli-lain, lainnya');

            // Empat sumbu klasifikasi program nasional.
            $table->string('diagnosis_type', 40)
                ->comment('terkonfirmasi-bakteriologis, terdiagnosis-klinis');
            $table->string('anatomical_site', 20)->comment('paru, ekstraparu');
            $table->string('treatment_history', 40)
                ->comment('baru, kambuh, gagal, putus-berobat, pindahan, lainnya, tidak-diketahui');
            $table->string('hiv_status', 20)->default('tidak-diketahui')
                ->comment('positif, negatif, tidak-diketahui — "tidak diketahui" jawaban yang sah');

            $table->date('hiv_tested_on')->nullable();
            $table->string('hiv_test_result', 20)->nullable()
                ->comment('reaktif, non-reaktif, indeterminate');

            // Skoring TB anak — hanya untuk anak.
            $table->unsignedTinyInteger('child_score')->nullable();
            $table->string('child_score_note', 100)->nullable();

            $table->date('treatment_started_on')->nullable();
            $table->string('regimen', 200)->nullable()->comment('Paduan OAT');
            $table->string('drug_source', 30)->nullable()
                ->comment('program-tb, bayar-sendiri, asuransi, lainnya');

            $table->string('outcome', 30)->default('belum')
                ->comment('belum, sembuh, pengobatan-lengkap, gagal, putus-berobat, meninggal, pindah');
            $table->date('outcome_on')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'registered_on']);
            $table->index(['report_year', 'report_quarter']);
            $table->index(['outcome', 'registered_on']);
        });

        DB::statement('ALTER TABLE '.self::S.".tb_cases
            ADD CONSTRAINT tb_cases_diagnosis_type_check
            CHECK (diagnosis_type IN ('terkonfirmasi-bakteriologis','terdiagnosis-klinis'))");

        DB::statement('ALTER TABLE '.self::S.".tb_cases
            ADD CONSTRAINT tb_cases_site_check
            CHECK (anatomical_site IN ('paru','ekstraparu'))");

        DB::statement('ALTER TABLE '.self::S.".tb_cases
            ADD CONSTRAINT tb_cases_history_check
            CHECK (treatment_history IN ('baru','kambuh','gagal','putus-berobat','pindahan','lainnya','tidak-diketahui'))");

        DB::statement('ALTER TABLE '.self::S.".tb_cases
            ADD CONSTRAINT tb_cases_hiv_check
            CHECK (hiv_status IN ('positif','negatif','tidak-diketahui')
                   AND (hiv_test_result IS NULL
                        OR hiv_test_result IN ('reaktif','non-reaktif','indeterminate')))");

        DB::statement('ALTER TABLE '.self::S.".tb_cases
            ADD CONSTRAINT tb_cases_outcome_check
            CHECK (outcome IN ('belum','sembuh','pengobatan-lengkap','gagal','putus-berobat','meninggal','pindah'))");

        DB::statement('ALTER TABLE '.self::S.'.tb_cases
            ADD CONSTRAINT tb_cases_quarter_check
            CHECK (report_quarter BETWEEN 1 AND 4)');

        DB::statement('ALTER TABLE '.self::S.'.tb_cases
            ADD CONSTRAINT tb_cases_child_score_check
            CHECK (child_score IS NULL OR child_score BETWEEN 0 AND 13)');

        // Hasil akhir selain "belum" wajib bertanggal: laporan program
        // dihitung per triwulan hasil akhirnya, bukan per triwulan
        // pendaftarannya.
        DB::statement('ALTER TABLE '.self::S.".tb_cases
            ADD CONSTRAINT tb_cases_outcome_date_check
            CHECK (outcome = 'belum' OR outcome_on IS NOT NULL)");

        DB::statement('CREATE UNIQUE INDEX tb_cases_patient_episode
            ON '.self::S.'.tb_cases (patient_id, registered_on)
            WHERE deleted_at IS NULL');
    }

    private function createFollowups(): void
    {
        Schema::create(self::S.'.tb_followups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('tb_case_id')->constrained(self::S.'.tb_cases');
            $table->string('phase', 30)
                ->comment('sebelum-pengobatan, akhir-tahap-awal, sisipan, bulan-ke-5, akhir-pengobatan');
            $table->date('examined_on');

            $table->string('smear_result', 20)->nullable()
                ->comment('negatif, scanty, 1+, 2+, 3+, tidak-dilakukan');
            $table->string('rapid_test_result', 30)->nullable()
                ->comment('rif-sensitif, rif-resisten, negatif, indeterminate, invalid, tidak-dilakukan');
            $table->string('culture_result', 20)->nullable();
            $table->string('lab_register_number', 30)->nullable();

            $table->timestampsTz();

            $table->index(['tb_case_id', 'examined_on']);
        });

        DB::statement('ALTER TABLE '.self::S.".tb_followups
            ADD CONSTRAINT tb_followups_phase_check
            CHECK (phase IN ('sebelum-pengobatan','akhir-tahap-awal','sisipan','bulan-ke-5','akhir-pengobatan'))");

        DB::statement('ALTER TABLE '.self::S.".tb_followups
            ADD CONSTRAINT tb_followups_smear_check
            CHECK (smear_result IS NULL
                   OR smear_result IN ('negatif','scanty','1+','2+','3+','tidak-dilakukan'))");

        // Satu pemeriksaan per tahap: tahap yang sama diperiksa dua kali
        // berarti salah satunya ralat, dan ralat menimpa yang lama.
        DB::statement('CREATE UNIQUE INDEX tb_followups_phase_unique
            ON '.self::S.'.tb_followups (tb_case_id, phase)');
    }
};
