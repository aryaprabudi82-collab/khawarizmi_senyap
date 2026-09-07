<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resume medis & perencanaan pemulangan (domain M item H).
 *
 * Menaungi resume_pasien, data_resume_pasien, perencanaan_pemulangan, dan
 * bukti_perencanaan_pemulangan_saksikeluarga.
 *
 * DIPERIKSA KE SKEMA KHANZA, DAN DI SINILAH TEMUANNYA. resume_pasien
 * memasang diagnosis sebagai KOLOM BERNOMOR: diagnosa_utama,
 * diagnosa_sekunder, _sekunder2, _sekunder3, _sekunder4 — lalu prosedur
 * dengan pola yang sama sampai _sekunder3. Dua akibatnya sama-sama buruk.
 *
 * Pertama, pasien dengan lima diagnosis sekunder kehilangan satu tanpa
 * pesan apa pun; yang hilang justru pada pasien paling rumit, yang
 * resumenya paling dibutuhkan.
 *
 * Kedua — dan ini yang lebih pokok — diagnosisnya DIKETIK ULANG. Rumah
 * sakit sudah punya diagnosis kunjungan itu di clinical.diagnoses,
 * ditegakkan dokter, berkode ICD-10, dengan aturan satu diagnosis utama.
 * Mengetiknya kembali ke kolom resume melahirkan sumber kedua yang bisa
 * berbeda dari rekam medisnya sendiri, dan resume yang berbeda dari
 * rekam medisnya adalah surat keterangan yang salah.
 *
 * KARENA ITU: RESUME MENYALIN, TIDAK MENGETIK ULANG. Saat difinalkan,
 * seluruh diagnosis dan prosedur kunjungan itu dibekukan apa adanya ke
 * dalam kolom jsonb — berapa pun jumlahnya. Dibekukan, bukan dirujuk,
 * karena resume yang sudah ditandatangani tidak boleh berubah isinya saat
 * koder memperbaiki ICD sebulan kemudian; tapi yang dibekukan adalah
 * salinan rekam medis, bukan ketikan kedua.
 *
 * OBAT PULANG JUGA DISALIN, dari resep yang benar-benar diserahkan
 * farmasi (pharmacy.v_prescription_detail), bukan diketik bebas seperti
 * kolom obat_pulang Khanza. Obat pulang yang diketik bebas adalah daftar
 * yang tidak pernah dicocokkan dengan apa yang betul-betul dibawa pulang
 * pasien.
 *
 * KONDISI PULANG DISALIN DARI ADMISI, tidak ditanyakan lagi. Rumah sakit
 * sudah menyatakan pasiennya pulang hidup atau meninggal saat admisi
 * ditutup; menanyakannya kedua kali membuka peluang resume menyatakan
 * "hidup" pada pasien yang tercatat meninggal. Pola yang sama dengan arah
 * transaksi kas dan arah cairan: disalin dari sumbernya, tidak diterima
 * dari pemanggil.
 *
 * PERENCANAAN PEMULANGAN — DUA PENGETATAN.
 *
 * Pertama, bantuan yang dibutuhkan pasien di rumah. Khanza memakai enum
 * MySQL untuk bantuan_diperlukan_dalam, yang artinya SATU pilihan saja:
 * pasien yang butuh bantuan mandi sekaligus minum obat harus memilih
 * salah satu. Di sini bentuknya daftar, karena kenyataannya memang daftar.
 *
 * Kedua, "belum ditanyakan" bukan "tidak". Pengaruh rawat inap terhadap
 * keluarga, pekerjaan, dan keuangan disimpan boolean YANG BOLEH NULL —
 * aturan yang sama seperti pertanyaan kecelakaan pada domain L. Menyimpan
 * false untuk pertanyaan yang belum sempat ditanyakan menghasilkan
 * perencanaan yang tampak lengkap padahal belum dikerjakan.
 *
 * PERENCANAAN PEMULANGAN DIMULAI DI AWAL, BUKAN SAAT PASIEN PULANG.
 * Standar akreditasi menghendaki perencanaan disusun sejak hari-hari
 * pertama rawat inap supaya keluarga punya waktu bersiap. Tabel ini
 * menyalin waktu masuk admisinya, dan servicenya bisa menyebutkan admisi
 * mana yang sudah lewat tenggat tanpa rencana — itulah gunanya
 * perencanaan pemulangan sebagai data, bukan sebagai formulir yang diisi
 * di menit terakhir.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createSummaries();
        $this->createPlans();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.discharge_plans');
        Schema::dropIfExists(self::S.'.discharge_summaries');
    }

    private function createSummaries(): void
    {
        Schema::create(self::S.'.discharge_summaries', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('admission_id')->nullable()
                ->comment('Kosong untuk resume rawat jalan; resume ranap selalu menunjuk admisinya');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->unsignedBigInteger('dpjp_practitioner_id')->nullable();
            $table->string('dpjp_name', 150)->nullable()
                ->comment('Yang bertanggung jawab atas isi resume, bukan yang mengetiknya');

            $table->timestampTz('admitted_at')->nullable();
            $table->timestampTz('discharged_at')->nullable();

            $table->text('chief_complaint')->nullable()->comment('keluhan_utama');
            $table->text('illness_course')->nullable()->comment('jalannya_penyakit');
            $table->text('physical_findings')->nullable();
            $table->text('supporting_exams')->nullable()->comment('pemeriksaan_penunjang');
            $table->text('lab_results')->nullable()->comment('hasil_laborat');
            $table->text('treatment')->nullable()->comment('terapi selama dirawat');

            // Salinan beku, jumlahnya tidak dibatasi — lihat catatan kelas.
            $table->jsonb('diagnoses')->default(DB::raw("'[]'::jsonb"))
                ->comment('Disalin dari clinical.diagnoses saat finalisasi, berapa pun banyaknya');
            $table->jsonb('procedures')->default(DB::raw("'[]'::jsonb"))
                ->comment('Disalin dari clinical.procedures saat finalisasi');
            $table->jsonb('discharge_medications')->default(DB::raw("'[]'::jsonb"))
                ->comment('Disalin dari resep yang diserahkan farmasi, bukan diketik bebas');

            $table->text('diet')->nullable();
            $table->text('follow_up_instruction')->nullable()->comment('anjuran/nasihat pulang');
            $table->date('control_on')->nullable();
            $table->string('control_unit', 120)->nullable();

            $table->string('condition_at_discharge', 20)->nullable()
                ->comment('Disalin dari admisi, tidak ditanyakan ulang');
            $table->string('discharge_manner', 40)->nullable()
                ->comment('Cara pulang, disalin dari admisi bila ada');
            $table->string('prognosis', 40)->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'discharged_at']);
            $table->index(['status', 'created_at']);
            $table->index('admission_id');
        });

        DB::statement('ALTER TABLE '.self::S.".discharge_summaries
            ADD CONSTRAINT discharge_summaries_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".discharge_summaries
            ADD CONSTRAINT discharge_summaries_condition_check
            CHECK (condition_at_discharge IS NULL
                   OR condition_at_discharge IN ('hidup','meninggal'))");

        // Resume final tanpa diagnosis adalah surat keterangan kosong: yang
        // membacanya di fasilitas lain tidak tahu pasien ini dirawat karena
        // apa. Ditegakkan basis data, bukan cuma service.
        DB::statement('ALTER TABLE '.self::S.".discharge_summaries
            ADD CONSTRAINT discharge_summaries_final_check
            CHECK (status <> 'final'
                   OR (finalized_at IS NOT NULL
                       AND jsonb_array_length(diagnoses) > 0
                       AND dpjp_name IS NOT NULL))");

        // Satu episode satu resume. Berbeda dari hasil penunjang yang boleh
        // berulang: EKG kedua adalah rekaman baru, resume kedua adalah dua
        // versi cerita yang sama. Ralat dilakukan dengan membatalkan lalu
        // membuat baru — karena itu yang dibatalkan tidak ikut dihitung.
        DB::statement('CREATE UNIQUE INDEX discharge_summaries_one_per_registration
            ON '.self::S.".discharge_summaries (registration_id)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    private function createPlans(): void
    {
        Schema::create(self::S.'.discharge_plans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('admission_id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('admission_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('admitted_at')
                ->comment('Disalin supaya tenggat 2x24 jam bisa dihitung tanpa menyeberang konteks');
            $table->date('planned_discharge_on')->nullable();
            $table->unsignedSmallInteger('estimated_care_days')->nullable();

            $table->text('admission_reason')->nullable();
            $table->string('medical_diagnosis', 200)->nullable();

            // NULL = belum ditanyakan. Lihat catatan kelas.
            $table->boolean('affects_family')->nullable();
            $table->string('affects_family_note', 200)->nullable();
            $table->boolean('affects_work_or_school')->nullable();
            $table->string('affects_work_or_school_note', 200)->nullable();
            $table->boolean('affects_finance')->nullable();
            $table->string('affects_finance_note', 200)->nullable();
            $table->boolean('anticipated_problems')->nullable();
            $table->string('anticipated_problems_note', 200)->nullable();

            // Daftar, bukan satu pilihan — lihat catatan kelas.
            $table->jsonb('assistance_needed')->default(DB::raw("'[]'::jsonb"));
            $table->string('assistance_note', 200)->nullable();

            $table->text('education_given')->nullable();
            $table->string('caregiver_name', 150)->nullable()
                ->comment('Keluarga yang menyaksikan dan melanjutkan perawatan di rumah — bukti_perencanaan_pemulangan_saksikeluarga');
            $table->string('caregiver_relation', 60)->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'created_at']);
            $table->index(['status', 'planned_discharge_on']);
        });

        DB::statement('ALTER TABLE '.self::S.".discharge_plans
            ADD CONSTRAINT discharge_plans_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".discharge_plans
            ADD CONSTRAINT discharge_plans_assistance_check
            CHECK (jsonb_typeof(assistance_needed) = 'array')");

        DB::statement('CREATE UNIQUE INDEX discharge_plans_one_per_admission
            ON '.self::S.".discharge_plans (admission_id)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }
};
