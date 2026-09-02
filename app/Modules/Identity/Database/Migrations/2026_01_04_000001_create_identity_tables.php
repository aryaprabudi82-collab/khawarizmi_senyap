<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks identity: master pasien.
 *
 * Catatan desain untuk 2.000 pasien/hari (~600 ribu kunjungan/tahun):
 *  - Primary key bigint identity. Nomor rekam medis tetap ada sebagai kolom
 *    unik terpisah, bukan dijadikan primary key seperti no_rkm_medis Khanza.
 *  - Pencarian nama memakai index trigram. Pada 3 juta pasien, ILIKE '%budi%'
 *    tanpa trigram berarti sequential scan setiap kali petugas mengetik.
 *  - Kode wilayah disimpan berikut nama yang sudah didenormalisasi, supaya
 *    layar daftar tidak perlu menyeberang konteks hanya untuk menampilkan
 *    nama kelurahan.
 */
return new class extends Migration
{
    private const S = 'identity';

    public function up(): void
    {
        // Diperlukan index trigram untuk pencarian nama pasien.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create(self::S . '.patients', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('medical_record_number', 20)->unique()->comment('Nomor RM, kunci yang dibaca manusia');
            $table->string('nik', 16)->nullable()->comment('NIK KTP, dasar deduplikasi dan Dukcapil');

            $table->string('name', 150);
            $table->char('sex', 1)->comment('L atau P');
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();

            $table->string('mother_name', 100)->nullable()->comment('Pembeda utama saat NIK belum ada');

            $table->string('blood_type', 3)->nullable();
            $table->string('religion', 30)->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('education', 30)->nullable();
            $table->string('occupation', 80)->nullable();

            $table->string('address', 255)->nullable();
            $table->string('rt_rw', 12)->nullable();

            // Kode wilayah Kemendagri berikut namanya, didenormalisasi.
            $table->string('village_code', 12)->nullable()->index();
            $table->string('village_name', 100)->nullable();
            $table->string('district_name', 100)->nullable();
            $table->string('city_name', 100)->nullable();
            $table->string('province_name', 100)->nullable();
            $table->string('postal_code', 8)->nullable();

            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();

            // Penanggung jawab.
            $table->string('guardian_name', 150)->nullable();
            $table->string('guardian_relation', 40)->nullable();
            $table->string('guardian_phone', 40)->nullable();
            $table->string('guardian_address', 255)->nullable();

            // Penanda yang harus terlihat di setiap layar klinis.
            $table->string('special_precautions', 150)->nullable();
            $table->string('special_precautions_color', 10)->nullable();

            $table->date('registered_on')->comment('Tanggal pertama kali terdaftar');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('birth_date');
            $table->index('phone');
        });

        DB::statement("ALTER TABLE " . self::S . ".patients ADD CONSTRAINT patients_sex_check
            CHECK (sex IN ('L','P'))");

        /*
         * NIK unik hanya untuk baris yang punya NIK dan belum dihapus. Bayi baru
         * lahir dan pasien gawat darurat tanpa identitas tetap bisa didaftarkan.
         */
        DB::statement('CREATE UNIQUE INDEX patients_nik_unique
            ON ' . self::S . '.patients (nik)
            WHERE nik IS NOT NULL AND deleted_at IS NULL');

        // Pencarian nama. GIN trigram menahan ILIKE tetap cepat di jutaan baris.
        DB::statement('CREATE INDEX patients_name_trgm_idx
            ON ' . self::S . '.patients USING gin (name gin_trgm_ops)');

        // Kandidat duplikat dicari lewat kombinasi ini saat NIK kosong.
        DB::statement('CREATE INDEX patients_dedup_idx
            ON ' . self::S . '.patients (lower(name), birth_date)');

        /*
         * Pengalokasi nomor rekam medis. Satu baris per awalan (mis. per tahun).
         *
         * Bukan SELECT MAX(no_rkm_medis)+1 seperti Khanza: dengan beberapa loket
         * mendaftar bersamaan, cara itu menghasilkan nomor kembar. Di sini
         * penambahan terjadi di dalam satu pernyataan UPDATE ... RETURNING,
         * sehingga PostgreSQL yang menjamin keunikannya.
         */
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 12)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        // Kontrak baca untuk konteks lain.
        DB::statement('CREATE VIEW ' . self::S . '.v_patient_summary AS
            SELECT id, medical_record_number, nik, name, sex, birth_date,
                   phone, special_precautions, special_precautions_color
            FROM ' . self::S . '.patients
            WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_patient_summary');

        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.patients');
    }
};
