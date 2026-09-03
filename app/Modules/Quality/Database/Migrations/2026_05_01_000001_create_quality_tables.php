<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks quality: insiden keselamatan pasien (IKP) dan PCRA/ICRA.
 *
 * Klasifikasi IKP mengikuti Permenkes 11/2017 tentang Keselamatan Pasien:
 * KPC (Kondisi Potensial Cedera), KNC (Kejadian Nyaris Cedera), KTC
 * (Kejadian Tidak Cedera), KTD (Kejadian Tidak Diharapkan), dan Sentinel.
 * Grading dampak memakai matriks grading risiko yang umum dipakai RS di
 * Indonesia (biru/hijau/kuning/merah), bukan istilah bebas.
 *
 * patient_id/registration_id/unit_id sengaja nullable: banyak insiden
 * (nyaris jatuh alat, near-miss tanpa pasien tertentu) tidak selalu punya
 * pasien atau kunjungan yang bisa dirujuk.
 */
return new class extends Migration
{
    private const S = 'quality';

    public function up(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create(self::S . '.incident_reports', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('report_number', 24)->unique();

            $table->unsignedBigInteger('patient_id')->nullable()->comment('ID pasien identity, referensi longgar — tidak semua insiden melibatkan pasien tertentu');
            $table->unsignedBigInteger('registration_id')->nullable()->comment('ID registrasi encounter, referensi longgar');
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');
            $table->string('location_detail', 150)->nullable()->comment('Lokasi rinci, mis. "Kamar 3, dekat pintu"');

            $table->string('incident_type', 10)->comment('kpc, knc, ktc, ktd, sentinel');
            $table->string('severity_band', 10)->comment('biru, hijau, kuning, merah — matriks grading risiko');
            $table->timestampTz('occurred_at');

            $table->text('description');
            $table->text('immediate_action')->nullable();

            $table->string('status', 20)->default('dilaporkan');
            $table->unsignedBigInteger('reported_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();

            $table->index('incident_type');
            $table->index('status');
            $table->index('occurred_at');
        });

        DB::statement("ALTER TABLE " . self::S . ".incident_reports ADD CONSTRAINT incident_reports_type_check
            CHECK (incident_type IN ('kpc','knc','ktc','ktd','sentinel'))");
        DB::statement("ALTER TABLE " . self::S . ".incident_reports ADD CONSTRAINT incident_reports_severity_check
            CHECK (severity_band IN ('biru','hijau','kuning','merah'))");
        DB::statement("ALTER TABLE " . self::S . ".incident_reports ADD CONSTRAINT incident_reports_status_check
            CHECK (status IN ('dilaporkan','ditinjau','ditutup'))");

        Schema::create(self::S . '.icra_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('assessment_number', 24)->unique();
            $table->string('project_name', 200);
            $table->string('project_type', 50)->comment('Jenis aktivitas proyek, mis. renovasi, konstruksi baru, perbaikan utilitas');
            $table->string('location', 150);
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');

            $table->string('infection_risk_level', 15);
            $table->string('fire_risk_level', 15);
            $table->string('safety_risk_level', 15);
            $table->string('utility_risk_level', 15);
            $table->string('risk_class', 5)->comment('Kelas I-IV hasil matriks ICRA, menentukan tingkat pengendalian wajib');

            $table->text('required_precautions')->nullable();
            $table->text('control_measures')->nullable();

            $table->unsignedBigInteger('assessed_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('assessed_at');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('aktif');

            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".icra_assessments ADD CONSTRAINT icra_risk_level_check
            CHECK (infection_risk_level IN ('rendah','sedang','tinggi','sangat-tinggi')
               AND fire_risk_level IN ('rendah','sedang','tinggi','sangat-tinggi')
               AND safety_risk_level IN ('rendah','sedang','tinggi','sangat-tinggi')
               AND utility_risk_level IN ('rendah','sedang','tinggi','sangat-tinggi'))");
        DB::statement("ALTER TABLE " . self::S . ".icra_assessments ADD CONSTRAINT icra_risk_class_check
            CHECK (risk_class IN ('I','II','III','IV'))");
        DB::statement("ALTER TABLE " . self::S . ".icra_assessments ADD CONSTRAINT icra_status_check
            CHECK (status IN ('aktif','selesai','dibatalkan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.icra_assessments');
        Schema::dropIfExists(self::S . '.incident_reports');
        Schema::dropIfExists(self::S . '.number_sequences');
    }
};
