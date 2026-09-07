<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan tindakan & pemantauan berkala (domain M item R).
 *
 * Menaungi laporan_tindakan, monitoring_reaksi_tranfusi, follow_up_dbd,
 * dan catatan_cek_gds.
 *
 * LAPORAN TINDAKAN MELEKAT PADA TINDAKANNYA. laporan_tindakan Khanza
 * berkunci no_rawat saja — persoalan yang sama dengan catatan anestesi
 * sebelum item M: pasien yang menjalani dua tindakan dalam satu
 * kunjungan punya laporan yang tidak bisa dibedakan milik tindakan yang
 * mana.
 *
 * HASIL LABORATORIUM PADA PEMANTAUAN DBD TIDAK DIKETIK ULANG TANPA
 * PENANDA. follow_up_dbd Khanza menyediakan hemoglobin, hematokrit,
 * leukosit, dan trombosit sebagai kolom teks — padahal keempatnya sudah
 * ada di modul penunjang. Persoalannya bukan sekadar penggandaan:
 * hematokrit dari laboratorium dan hematokrit point-of-care di samping
 * tempat tidur BUKAN angka yang setara, sementara keputusan pada demam
 * berdarah bersandar pada kenaikan hematokrit 20 persen. Mencampur
 * keduanya tanpa penanda membuat kenaikan yang sebenarnya berasal dari
 * pergantian alat terbaca sebagai perburukan pasien.
 *
 * Maka di sini tiap nilai menyebut SUMBERNYA, dan yang berasal dari
 * laboratorium boleh menunjuk permintaan penunjangnya.
 *
 * REAKSI TRANSFUSI MENUNGGU DOMAIN N. monitoring_reaksi_tranfusi
 * menyimpan no_kantong tanpa tautan ke unit transfusi darah, dan domain
 * N (UTD) belum digarap. Nomor kantong di sini disimpan apa adanya
 * sebagai teks, DENGAN CATATAN bahwa ia belum bisa diperiksa terhadap
 * kantong yang benar-benar dikeluarkan — pemeriksaan itu ditambahkan
 * saat domain N dibangun, bukan dikarang sekarang.
 *
 * TANDA VITAL TIDAK DIDUPLIKASI pada ketiga tabel pemantauan; panel
 * observasi sejak item D sudah menanganinya.
 *
 * GULA DARAH DAN OBATNYA DICATAT BERSAMA, sebagaimana catatan_cek_gds
 * Khanza — dan itu benar: dosis insulin ditentukan oleh angka gula
 * darah pada saat itu juga, dan memisahkannya membuat pasangan
 * angka-dosis harus dicocokkan kembali dari dua tabel berbeda.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createProcedureReports();
        $this->createTransfusionMonitorings();
        $this->createDengueMonitorings();
        $this->createGlucoseMonitorings();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.glucose_monitorings');
        Schema::dropIfExists(self::S.'.dengue_monitorings');
        Schema::dropIfExists(self::S.'.transfusion_monitorings');
        Schema::dropIfExists(self::S.'.procedure_reports');
    }

    private function createProcedureReports(): void
    {
        Schema::create(self::S.'.procedure_reports', function (Blueprint $table) {
            $table->bigIncrements('id');

            // NOT NULL, dan itu intinya — lihat catatan kelas.
            $table->unsignedBigInteger('procedure_id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('reported_at');
            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150);
            $table->string('assistant_name', 150)->nullable();

            $table->string('pre_procedure_diagnosis', 200)->nullable();
            $table->string('post_procedure_diagnosis', 200)->nullable();
            $table->string('procedure_name', 300);
            $table->text('description');
            $table->text('findings')->nullable();
            $table->text('conclusion');
            $table->text('recommendation')->nullable();
            $table->text('complications')->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'reported_at']);
            $table->index(['patient_id', 'reported_at']);
            $table->index(['status', 'reported_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".procedure_reports
            ADD CONSTRAINT procedure_reports_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        // Laporan tindakan tanpa kesimpulan adalah uraian yang tidak
        // ditafsirkan siapa pun — aturan yang sama dengan hasil penunjang
        // pada item G.
        DB::statement('ALTER TABLE '.self::S.".procedure_reports
            ADD CONSTRAINT procedure_reports_final_check
            CHECK (status <> 'final'
                   OR (finalized_at IS NOT NULL
                       AND conclusion IS NOT NULL AND btrim(conclusion) <> ''))");

        DB::statement('CREATE UNIQUE INDEX procedure_reports_one_per_procedure
            ON '.self::S.".procedure_reports (procedure_id)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    private function createTransfusionMonitorings(): void
    {
        Schema::create(self::S.'.transfusion_monitorings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->string('blood_product', 60);
            $table->string('bag_number', 40)
                ->comment('Belum bisa diperiksa terhadap kantong yang dikeluarkan UTD — domain N belum digarap');
            $table->string('insertion_site', 60)->nullable();

            $table->timestampTz('observed_at');
            $table->string('phase', 30)
                ->comment('sebelum, 15-menit, selama, selesai, 1-jam-setelah');

            // TIDAK ADA KOLOM TANDA VITAL — panel observasi sejak item D.

            $table->boolean('reaction_occurred')->nullable()
                ->comment('NULL berarti belum dinilai, bukan "tidak ada reaksi"');
            $table->jsonb('reaction_signs')->default(DB::raw("'[]'::jsonb"));
            $table->string('reaction_severity', 20)->nullable()->comment('ringan, sedang, berat');
            $table->text('action_taken')->nullable();

            $table->unsignedBigInteger('observed_by')->nullable();
            $table->string('observed_by_name', 150);

            $table->text('note')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'observed_at']);
            $table->index(['bag_number', 'observed_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".transfusion_monitorings
            ADD CONSTRAINT transfusion_monitorings_phase_check
            CHECK (phase IN ('sebelum','15-menit','selama','selesai','1-jam-setelah'))");

        DB::statement('ALTER TABLE '.self::S.".transfusion_monitorings
            ADD CONSTRAINT transfusion_monitorings_severity_check
            CHECK (reaction_severity IS NULL
                   OR reaction_severity IN ('ringan','sedang','berat'))");

        DB::statement('ALTER TABLE '.self::S.".transfusion_monitorings
            ADD CONSTRAINT transfusion_monitorings_signs_check
            CHECK (jsonb_typeof(reaction_signs) = 'array')");

        // Reaksi yang dinyatakan terjadi harus menyebut tandanya dan apa
        // yang dikerjakan: transfusi yang bereaksi wajib dihentikan, dan
        // catatan tanpa tindakan tidak membuktikan itu dilakukan.
        DB::statement('ALTER TABLE '.self::S.".transfusion_monitorings
            ADD CONSTRAINT transfusion_monitorings_reaction_check
            CHECK (reaction_occurred IS NOT TRUE
                   OR (jsonb_array_length(reaction_signs) > 0
                       AND action_taken IS NOT NULL AND btrim(action_taken) <> ''))");
    }

    private function createDengueMonitorings(): void
    {
        Schema::create(self::S.'.dengue_monitorings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('observed_at');

            // SUMBERNYA IKUT DICATAT — lihat catatan kelas.
            $table->string('source', 20)->comment('laboratorium, point-of-care');
            $table->unsignedBigInteger('order_id')->nullable()
                ->comment('Permintaan penunjang asalnya, bila berasal dari laboratorium');

            $table->decimal('haemoglobin_g_dl', 4, 1)->nullable();
            $table->decimal('haematocrit_percent', 4, 1)->nullable();
            $table->unsignedInteger('leucocytes_per_ul')->nullable();
            $table->unsignedInteger('platelets_per_ul')->nullable();

            $table->text('fluid_therapy')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'observed_at']);
            $table->index(['patient_id', 'observed_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".dengue_monitorings
            ADD CONSTRAINT dengue_monitorings_source_check
            CHECK (source IN ('laboratorium','point-of-care'))");

        // Nilai yang mengaku dari laboratorium tapi tidak menunjuk
        // permintaannya adalah nilai yang tidak bisa ditelusuri; kalau
        // memang diperiksa di samping tempat tidur, sumbernya point-of-care.
        DB::statement('ALTER TABLE '.self::S.".dengue_monitorings
            ADD CONSTRAINT dengue_monitorings_order_check
            CHECK (source <> 'laboratorium' OR order_id IS NOT NULL)");

        // Pemantauan tanpa satu pun nilai bukan pemantauan.
        DB::statement('ALTER TABLE '.self::S.'.dengue_monitorings
            ADD CONSTRAINT dengue_monitorings_not_empty_check
            CHECK (haemoglobin_g_dl IS NOT NULL
                   OR haematocrit_percent IS NOT NULL
                   OR leucocytes_per_ul IS NOT NULL
                   OR platelets_per_ul IS NOT NULL)');
    }

    private function createGlucoseMonitorings(): void
    {
        Schema::create(self::S.'.glucose_monitorings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('checked_at');
            $table->string('timing', 30)
                ->comment('puasa, sebelum-makan, 2-jam-setelah-makan, sewaktu, sebelum-tidur');
            $table->unsignedSmallInteger('glucose_mg_dl');
            $table->string('source', 20)->default('point-of-care')
                ->comment('laboratorium, point-of-care — glukometer dan laboratorium tidak setara');

            // Obat dicatat bersama angkanya, dan itu benar: dosis insulin
            // ditentukan oleh angka gula darah pada saat itu juga.
            $table->string('insulin', 60)->nullable();
            $table->string('insulin_dose_unit', 30)->nullable();
            $table->string('oral_agent', 60)->nullable();
            $table->text('action_taken')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'checked_at']);
            $table->index(['patient_id', 'checked_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".glucose_monitorings
            ADD CONSTRAINT glucose_monitorings_timing_check
            CHECK (timing IN ('puasa','sebelum-makan','2-jam-setelah-makan','sewaktu','sebelum-tidur'))");

        DB::statement('ALTER TABLE '.self::S.".glucose_monitorings
            ADD CONSTRAINT glucose_monitorings_source_check
            CHECK (source IN ('laboratorium','point-of-care'))");

        // Rentang yang mustahil menandakan salah ketik, dan salah ketik
        // pada gula darah berujung pada dosis insulin yang salah.
        DB::statement('ALTER TABLE '.self::S.'.glucose_monitorings
            ADD CONSTRAINT glucose_monitorings_range_check
            CHECK (glucose_mg_dl BETWEEN 10 AND 1500)');

        // Insulin yang disebutkan harus menyebut dosisnya — "diberi
        // insulin" tanpa unitnya bukan instruksi yang bisa dijalankan
        // maupun ditelusuri.
        DB::statement('ALTER TABLE '.self::S.".glucose_monitorings
            ADD CONSTRAINT glucose_monitorings_insulin_dose_check
            CHECK (insulin IS NULL
                   OR (insulin_dose_unit IS NOT NULL AND btrim(insulin_dose_unit) <> ''))");
    }
};
