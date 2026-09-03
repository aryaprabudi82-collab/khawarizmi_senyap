<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rujukan_masuk & rujukan_keluar (Khanza domain A, kelas DlgRujukMasuk/
 * DlgRujuk) — keduanya tercatat context=encounter tanpa penanda paket Java
 * lain di Khanza_Functional_Dependency_Map.xlsx, jadi tetap di sini.
 *
 * rujukan_masuk (pasien datang MEMBAWA rujukan dari faskes lain) diperluas
 * langsung di encounter.registrations — sebelumnya cuma referral_number
 * (teks bebas), sekarang ditambah asal faskes & tanggal rujukan. Bukan
 * tabel baru: satu rujukan masuk selalu menempel pada satu registrasi,
 * konsisten dengan pola registrations yang memang didesain lebar/
 * didenormalisasi untuk layar daftar (unit_name/practitioner_name/dst.).
 *
 * rujukan_keluar (pasien DIKIRIM ke faskes lain) sebaliknya tabel baru —
 * bentuknya beda: surat rujukan dengan nomor sendiri, tujuan, diagnosis,
 * dan status aktif/dibatalkan, diterbitkan dokter berdasarkan penilaian
 * klinis, bisa dicetak (pakai layouts.print yang sudah ada). Diagnosis
 * disimpan sebagai teks bebas di baris rujukan sendiri (bukan menunjuk
 * clinical.diagnoses) supaya encounter tidak perlu bergantung ke konteks
 * lain untuk fitur administratif ini.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        Schema::table(self::S . '.registrations', function (Blueprint $table) {
            $table->string('referring_facility_name', 150)->nullable()->after('referral_number');
            $table->string('referring_facility_code', 30)->nullable()->comment('Kode PPK Kemenkes bila diketahui, teks bebas — belum tersambung SISRUTE/rujukan online')->after('referring_facility_name');
            $table->date('referral_date')->nullable()->after('referring_facility_code');
        });

        Schema::create(self::S . '.outgoing_referrals', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('referral_number', 24)->unique();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->string('destination_facility_name', 150);
            $table->string('destination_facility_code', 30)->nullable()->comment('Kode PPK Kemenkes bila diketahui, teks bebas');
            $table->text('reason')->comment('Alasan rujukan');
            $table->text('diagnosis')->nullable()->comment('Diagnosis pasien saat dirujuk, teks bebas — bukan rujukan ke clinical.diagnoses');

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();
            $table->timestampTz('referred_at');
            $table->string('status', 20)->default('aktif');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('registration_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".outgoing_referrals ADD CONSTRAINT outgoing_referrals_status_check
            CHECK (status IN ('aktif','dibatalkan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.outgoing_referrals');

        Schema::table(self::S . '.registrations', function (Blueprint $table) {
            $table->dropColumn(['referring_facility_name', 'referring_facility_code', 'referral_date']);
        });
    }
};
