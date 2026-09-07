<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat imunisasi & cacat fisik pasien (domain M item S).
 *
 * Menaungi riwayat_imunisasi dan separuh cacat_fisik yang hilang.
 *
 * riwayat_imunisasi KHANZA HANYA PUNYA TIGA KOLOM: no_rkm_medis,
 * kode_imunisasi, dan no_imunisasi (dosis ke berapa). Tidak ada tanggal
 * pemberian, nomor batch, kedaluwarsa, lokasi suntikan, maupun siapa
 * yang menyuntik. Tiga hal jadi mustahil karenanya:
 *
 * - Jadwal dosis berikutnya tidak bisa dihitung, karena tidak ada
 *   tanggal untuk menambahkan jaraknya.
 * - Penarikan vaksin tidak bisa ditindaklanjuti: saat satu batch
 *   ditarik peredarannya, tidak ada cara menemukan pasien mana yang
 *   menerimanya.
 * - Pelaporan ke program imunisasi nasional tidak punya bahan, karena
 *   yang dilaporkan adalah cakupan per periode dan periodenya butuh
 *   tanggal.
 *
 * Keempatnya ditambahkan di sini, dan tanggal pemberian dijadikan WAJIB.
 *
 * SATU DOSIS TIDAK BOLEH TERCATAT DUA KALI. Kunci Khanza (pasien,
 * vaksin, nomor dosis) sudah benar bentuknya; yang ditambahkan di sini
 * indeks unik parsial supaya pembatalan tetap memungkinkan pencatatan
 * ulang — pola yang sama seperti entitas lain yang boleh diralat.
 *
 * CACAT FISIK: SEPARUH YANG HILANG. cacat_fisik Khanza hanya master
 * (id, nama_cacat) tanpa tabel yang mencatat pasien mana punya cacat
 * yang mana — daftar jenis tanpa pemakaiannya tidak menjawab
 * pertanyaan apa pun. Di sini keduanya lengkap, dan yang dicatat bukan
 * cuma jenisnya melainkan juga sejak kapan, apakah bawaan atau
 * didapat, serta alat bantu yang dipakai — karena itulah yang
 * menentukan penyesuaian pelayanan.
 *
 * MELEKAT PADA PASIEN, BUKAN PADA KUNJUNGAN. Cacat fisik bukan keadaan
 * satu kunjungan, dan mencatatnya per kunjungan akan membuat
 * penyesuaian pelayanan harus ditemukan ulang tiap kali pasien datang.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createImmunisations();
        $this->createDisabilities();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.patient_disabilities');
        Schema::dropIfExists(self::S.'.immunisations');
    }

    private function createImmunisations(): void
    {
        Schema::create(self::S.'.immunisations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->unsignedBigInteger('registration_id')->nullable()
                ->comment('Kosong untuk imunisasi yang dilaporkan pasien dari fasilitas lain');

            $table->string('immunisation_code', 20);
            $table->string('immunisation_name', 150)->comment('Disalin: nama master boleh berubah');
            $table->unsignedSmallInteger('dose_number');

            // Keempatnya tidak ada di Khanza.
            $table->date('given_on')->comment('WAJIB: tanpa ini jadwal dosis berikutnya tidak bisa dihitung');
            $table->string('batch_number', 60)->nullable()
                ->comment('Tanpa ini penarikan batch tidak bisa ditindaklanjuti');
            $table->date('expires_on')->nullable();
            $table->string('injection_site', 60)->nullable();
            $table->string('route', 30)->nullable();

            $table->string('source', 20)->default('rumah-sakit-ini')
                ->comment('rumah-sakit-ini, fasilitas-lain, dilaporkan-keluarga');
            $table->string('facility_name', 150)->nullable();

            $table->unsignedBigInteger('given_by')->nullable();
            $table->string('given_by_name', 150)->nullable();

            $table->text('reaction')->nullable()
                ->comment('Kejadian ikutan pasca imunisasi bila ada');

            $table->string('status', 20)->default('tercatat')->comment('tercatat, dibatalkan');
            $table->string('cancellation_reason', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'given_on']);
            $table->index(['immunisation_code', 'given_on']);
            $table->index('batch_number');
        });

        DB::statement('ALTER TABLE '.self::S.".immunisations
            ADD CONSTRAINT immunisations_status_check
            CHECK (status IN ('tercatat','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".immunisations
            ADD CONSTRAINT immunisations_source_check
            CHECK (source IN ('rumah-sakit-ini','fasilitas-lain','dilaporkan-keluarga'))");

        DB::statement('ALTER TABLE '.self::S.'.immunisations
            ADD CONSTRAINT immunisations_dose_check
            CHECK (dose_number >= 1)');

        // Vaksin yang sudah kedaluwarsa saat diberikan adalah kejadian
        // yang harus ketahuan, bukan disimpan diam-diam.
        DB::statement('ALTER TABLE '.self::S.'.immunisations
            ADD CONSTRAINT immunisations_expiry_check
            CHECK (expires_on IS NULL OR expires_on >= given_on)');

        DB::statement('ALTER TABLE '.self::S.".immunisations
            ADD CONSTRAINT immunisations_cancel_check
            CHECK (status <> 'dibatalkan'
                   OR (cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> ''))");

        DB::statement('CREATE UNIQUE INDEX immunisations_dose_unique
            ON '.self::S.".immunisations (patient_id, immunisation_code, dose_number)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    private function createDisabilities(): void
    {
        Schema::create(self::S.'.patient_disabilities', function (Blueprint $table) {
            $table->bigIncrements('id');

            // MELEKAT PADA PASIEN — lihat catatan kelas.
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->unsignedBigInteger('recorded_in_registration_id')->nullable();

            $table->string('disability_code', 20);
            $table->string('disability_name', 100)->comment('Disalin saat pencatatan');

            $table->string('onset', 20)->nullable()->comment('bawaan, didapat');
            $table->date('onset_on')->nullable();
            $table->string('severity', 20)->nullable()->comment('ringan, sedang, berat');
            $table->string('assistive_device', 150)->nullable()
                ->comment('Kursi roda, tongkat, alat bantu dengar — menentukan penyesuaian pelayanan');
            $table->text('service_adjustment')->nullable();

            $table->string('status', 20)->default('aktif')->comment('aktif, pulih, dikoreksi');
            $table->string('status_reason', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampTz('recorded_at');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'status']);
        });

        DB::statement('ALTER TABLE '.self::S.".patient_disabilities
            ADD CONSTRAINT patient_disabilities_onset_check
            CHECK (onset IS NULL OR onset IN ('bawaan','didapat'))");

        DB::statement('ALTER TABLE '.self::S.".patient_disabilities
            ADD CONSTRAINT patient_disabilities_severity_check
            CHECK (severity IS NULL OR severity IN ('ringan','sedang','berat'))");

        DB::statement('ALTER TABLE '.self::S.".patient_disabilities
            ADD CONSTRAINT patient_disabilities_status_check
            CHECK (status IN ('aktif','pulih','dikoreksi'))");

        // Cacat bawaan tidak bisa punya tanggal mulai yang lebih baru
        // daripada kelahirannya — tapi tanggal lahir milik konteks
        // identity, jadi yang bisa ditegakkan di sini cuma bahwa cacat
        // bawaan TIDAK menyebut tanggal mulai: ia ada sejak lahir.
        DB::statement('ALTER TABLE '.self::S.".patient_disabilities
            ADD CONSTRAINT patient_disabilities_congenital_check
            CHECK (onset <> 'bawaan' OR onset_on IS NULL)");

        // Perubahan status wajib beralasan: "pulih" pada catatan cacat
        // fisik adalah pernyataan besar, dan "dikoreksi" berarti
        // catatannya memang keliru.
        DB::statement('ALTER TABLE '.self::S.".patient_disabilities
            ADD CONSTRAINT patient_disabilities_status_reason_check
            CHECK (status = 'aktif'
                   OR (status_reason IS NOT NULL AND btrim(status_reason) <> ''))");

        DB::statement('CREATE UNIQUE INDEX patient_disabilities_active_unique
            ON '.self::S.".patient_disabilities (patient_id, disability_code)
            WHERE status = 'aktif' AND deleted_at IS NULL");
    }
};
