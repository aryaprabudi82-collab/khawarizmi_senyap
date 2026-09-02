<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks encounter: registrasi dan kunjungan. Domain A pada peta Khanza.
 *
 * Catatan desain untuk 2.000 pasien/hari:
 *
 *  - Primary key bigint identity. Nomor registrasi tetap ada sebagai kolom unik.
 *    Khanza memakai no_rawat varchar(17) berisi '2026/09/02/000001' sebagai
 *    primary key dan menyebarkannya sebagai foreign key ke 30-an tabel; pada
 *    puluhan juta baris, index varchar(17) jauh lebih mahal daripada bigint.
 *
 *  - Tidak ada foreign key ke identity.patients, organization.units, maupun
 *    catalog.payers karena beda schema. Id-nya disimpan, ditambah beberapa
 *    kolom yang didenormalisasi supaya layar daftar — bagian terbesar sebuah
 *    SIMRS — bisa dirender dengan satu query tanpa menyeberang konteks.
 *
 *  - Nomor antrean dijamin unik oleh unique index, bukan oleh kesopanan kode.
 */
return new class extends Migration
{
    private const S = 'encounter';

    public function up(): void
    {
        Schema::create(self::S . '.registrations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('registration_number', 24)->unique();

            // Rujukan lintas konteks: id disimpan, foreign key tidak dibuat.
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->unsignedBigInteger('payer_id');

            // Salinan untuk layar daftar. Diperbarui lewat domain event.
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);
            $table->string('unit_name', 150);
            $table->string('practitioner_name', 150)->nullable();
            $table->string('payer_name', 150);

            $table->date('service_date');
            $table->timestampTz('registered_at');
            $table->unsignedSmallInteger('queue_number');

            $table->string('visit_type', 10)->comment('baru, lama');
            $table->string('care_type', 10)->comment('ralan, ranap');
            $table->string('status', 20)->default('terdaftar')
                ->comment('terdaftar, dipanggil, dilayani, selesai, batal, tidak-hadir');

            // Umur direkam saat daftar: umur pasien berubah, angka di dokumen tidak.
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->unsignedSmallInteger('age_months')->nullable();
            $table->unsignedSmallInteger('age_days')->nullable();

            $table->decimal('registration_fee', 14, 2)->default(0);
            $table->string('payment_status', 20)->default('belum-bayar')->comment('belum-bayar, sudah-bayar, dijamin');

            // Penanggung jawab khusus kunjungan ini, bila beda dari data pasien.
            $table->string('guardian_name', 150)->nullable();
            $table->string('guardian_relation', 40)->nullable();
            $table->string('guardian_phone', 40)->nullable();

            $table->string('referral_number', 60)->nullable()->comment('Nomor rujukan, mis. dari FKTP');
            $table->string('membership_number', 40)->nullable()->comment('Nomor kartu peserta penjamin');

            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            /*
             * Index mengikuti pola query yang benar-benar dipakai:
             *  1. daftar antrean satu poli pada satu hari
             *  2. daftar pasien satu dokter pada satu hari
             *  3. riwayat kunjungan seorang pasien
             *  4. papan status harian
             */
            $table->index(['service_date', 'unit_id'], 'reg_date_unit_idx');
            $table->index(['service_date', 'practitioner_id'], 'reg_date_practitioner_idx');
            $table->index(['patient_id', 'service_date'], 'reg_patient_history_idx');
            $table->index(['service_date', 'status'], 'reg_date_status_idx');
            $table->index('payer_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".registrations ADD CONSTRAINT registrations_visit_type_check
            CHECK (visit_type IN ('baru','lama'))");
        DB::statement("ALTER TABLE " . self::S . ".registrations ADD CONSTRAINT registrations_care_type_check
            CHECK (care_type IN ('ralan','ranap'))");
        DB::statement("ALTER TABLE " . self::S . ".registrations ADD CONSTRAINT registrations_status_check
            CHECK (status IN ('terdaftar','dipanggil','dilayani','selesai','batal','tidak-hadir'))");
        DB::statement("ALTER TABLE " . self::S . ".registrations ADD CONSTRAINT registrations_payment_status_check
            CHECK (payment_status IN ('belum-bayar','sudah-bayar','dijamin'))");

        /*
         * Jaminan sesungguhnya bahwa nomor antrean tidak pernah kembar.
         * Kalau logika aplikasi bocor, basis data yang menolak — bukan
         * ketahuan berminggu-minggu kemudian dari keluhan pasien.
         */
        DB::statement('CREATE UNIQUE INDEX registrations_queue_unique
            ON ' . self::S . '.registrations (service_date, unit_id, queue_number)
            WHERE status <> \'batal\'');

        /*
         * Pengalokasi nomor antrean harian per unit.
         *
         * Dialokasikan lewat INSERT ... ON CONFLICT DO UPDATE ... RETURNING,
         * satu pernyataan atomik. Khanza memakai SELECT MAX(...)+1, yang pada
         * beberapa loket pendaftaran berjalan bersamaan menghasilkan nomor
         * kembar — persis kelas bug yang paling sulit dilacak karena hanya
         * muncul saat ramai.
         */
        Schema::create(self::S . '.queue_counters', function (Blueprint $table) {
            $table->date('service_date');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedSmallInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();

            $table->primary(['service_date', 'unit_id']);
        });

        // Pengalokasi nomor registrasi, satu baris per awalan tanggal.
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        // DPJP tambahan di luar dokter penanggung jawab utama.
        Schema::create(self::S . '.registration_practitioners', function (Blueprint $table) {
            $table->foreignId('registration_id')->constrained(self::S . '.registrations')->cascadeOnDelete();
            $table->unsignedBigInteger('practitioner_id');
            $table->string('practitioner_name', 150);
            $table->string('role', 30)->default('dpjp-tambahan');
            $table->unsignedSmallInteger('sequence')->default(1);

            $table->primary(['registration_id', 'sequence']);
            $table->index('practitioner_id');
        });

        // Kontrak baca untuk konteks clinical, order, dan billing nanti.
        DB::statement("CREATE VIEW " . self::S . ".v_registration_summary AS
            SELECT id, registration_number, patient_id, patient_mrn, patient_name,
                   unit_id, unit_name, practitioner_id, practitioner_name,
                   payer_id, payer_name, service_date, registered_at,
                   queue_number, care_type, status,
                   registration_fee, payment_status
            FROM " . self::S . ".registrations
            WHERE status <> 'batal'");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_registration_summary');

        Schema::dropIfExists(self::S . '.registration_practitioners');
        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.queue_counters');
        Schema::dropIfExists(self::S . '.registrations');
    }
};
