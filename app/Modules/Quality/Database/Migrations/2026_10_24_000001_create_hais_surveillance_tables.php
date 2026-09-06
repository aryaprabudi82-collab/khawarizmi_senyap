<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surveilans HAIs — infeksi yang didapat selama perawatan (domain J item E).
 *
 * Empat kode laporan HAIs Khanza (harian_HAIs, harian_HAIs2, bulanan_HAIs,
 * hais_perbangsal) tidak bisa dibangun dari data yang ada: konteks quality
 * baru memegang AUDIT KEPATUHAN bundle pencegahan (ppi_audits punya
 * bundle-vap, bundle-iadp, bundle-isk, bundle-ido sejak domain C), bukan
 * KEJADIAN infeksinya. Kepatuhan bundle 100% dan angka infeksi nol adalah
 * dua pernyataan yang berbeda; yang kedua belum pernah tercatat.
 *
 * DUA TABEL, DAN YANG KEDUA YANG MENENTUKAN KEBENARAN ANGKANYA.
 *
 * infection_events mencatat kejadiannya. device_days mencatat PENYEBUTNYA:
 * berapa hari-alat yang terpasang di tiap bangsal. Angka HAIs dilaporkan
 * sebagai insiden per 1000 hari-alat (Permenkes 27/2017 tentang PPI),
 * bukan sebagai jumlah kejadian, karena jumlah kejadian saja membuat
 * bangsal yang merawat lebih banyak pasien selalu terlihat lebih buruk
 * daripada bangsal kecil — padahal bisa jadi justru lebih aman per
 * pasiennya. Membangun tabel kejadian tanpa penyebutnya akan menghasilkan
 * laporan yang rapi, mudah dibaca, dan menyesatkan.
 *
 * Plebitis dan dekubitus tetap ikut meski bukan infeksi-terkait-alat
 * (keduanya dilaporkan per 1000 hari-rawat, bukan hari-alat) karena
 * keduanya bagian dari surveilans HAIs rutin di Indonesia.
 */
return new class extends Migration
{
    private const S = 'quality';

    /** Jenis infeksi yang disurvei rutin, berikut alat yang jadi penyebutnya. */
    private const JENIS = [
        'vap', 'iadp', 'isk', 'ido', 'plebitis', 'dekubitus',
    ];

    private const ALAT = [
        'ventilator', 'central-line', 'kateter-urin', 'infus-perifer', 'tanpa-alat',
    ];

    public function up(): void
    {
        Schema::create(self::S . '.infection_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_number', 30)->unique();

            // Referensi longgar lintas konteks — tanpa FK, mengikuti pola
            // yang sama seperti unit_id pada ppi_audits.
            $table->unsignedBigInteger('admission_id')->nullable()->comment('ID admisi inpatient, referensi longgar');
            $table->unsignedBigInteger('patient_id')->comment('ID pasien identity, referensi longgar');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 120);

            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit/bangsal organization, referensi longgar');
            $table->string('unit_name', 120)->comment('Disalin supaya laporan per bangsal bisa satu query');

            $table->string('infection_type', 20)->comment('vap/iadp/isk/ido/plebitis/dekubitus');
            $table->string('device', 20)->nullable()->comment('Alat yang terpasang saat infeksi muncul; tanpa-alat untuk plebitis/dekubitus');

            $table->date('onset_on')->comment('Tanggal gejala pertama muncul — bukan tanggal dilaporkan');
            $table->unsignedSmallInteger('days_after_admission')->nullable()
                ->comment('Selisih onset dari masuk; infeksi <48 jam umumnya bukan HAIs, dinilai tim PPI');

            $table->text('clinical_criteria')->comment('Dasar penetapan menurut kriteria surveilans');
            $table->string('culture_result', 200)->nullable()->comment('Hasil kultur bila ada; kosong bukan berarti tidak ada infeksi');
            $table->text('corrective_action')->nullable();

            $table->unsignedBigInteger('reported_by')->nullable()->comment('ID user pelapor, referensi longgar');
            $table->string('reported_by_name', 120)->nullable();

            $table->timestampsTz();

            $table->index(['onset_on', 'infection_type']);
            $table->index(['unit_name', 'onset_on']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".infection_events
            ADD CONSTRAINT infection_events_type_check
            CHECK (infection_type IN ('" . implode("','", self::JENIS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".infection_events
            ADD CONSTRAINT infection_events_device_check
            CHECK (device IS NULL OR device IN ('" . implode("','", self::ALAT) . "'))");

        /**
         * Penyebut surveilans: berapa hari-alat dan hari-rawat di tiap
         * bangsal pada satu tanggal. Dicatat harian oleh perawat PPI —
         * inilah cara angka HAIs dihitung di seluruh dunia, dan tanpa
         * tabel ini laporan hanya bisa menyajikan jumlah mentah.
         */
        Schema::create(self::S . '.device_days', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit/bangsal organization, referensi longgar');
            $table->string('unit_name', 120);
            $table->date('counted_on');

            $table->unsignedInteger('patient_days')->default(0)->comment('Jumlah pasien dirawat hari itu — penyebut plebitis & dekubitus');
            $table->unsignedInteger('ventilator_days')->default(0);
            $table->unsignedInteger('central_line_days')->default(0);
            $table->unsignedInteger('urinary_catheter_days')->default(0);
            $table->unsignedInteger('peripheral_line_days')->default(0);

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            // Satu baris per bangsal per tanggal. Kalau boleh ganda,
            // penyebutnya menggelembung dan angka HAIs jadi terlalu kecil
            // — arah kesalahan yang menguntungkan pelapor.
            $table->unique(['unit_name', 'counted_on'], 'device_days_unit_tanggal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.device_days');
        Schema::dropIfExists(self::S . '.infection_events');
    }
};
