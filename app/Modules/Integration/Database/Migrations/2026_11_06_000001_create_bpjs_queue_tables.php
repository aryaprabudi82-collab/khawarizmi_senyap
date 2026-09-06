<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean Mobile JKN & SIRANAP (domain L sisa) — 4 kode wajib.
 *
 * Menaungi bpjs_task_id, bpjs_antrean_pertanggal,
 * batal_pendaftaran_mobilejkn_bpjs, dan siranap_ketersediaan_kamar.
 *
 * ANTREAN MOBILE JKN ADALAH KEWAJIBAN, bukan fitur tambahan. Sejak 2022
 * rumah sakit wajib mengirim data antrean ke BPJS supaya peserta bisa
 * mendaftar dan melihat posisi antreannya dari aplikasi Mobile JKN. Yang
 * dilihat pasien adalah data yang KITA kirim; antrean yang tidak
 * diperbarui membuat pasien datang pada waktu yang salah.
 *
 * TASK ID BUKAN DATA BARU — ia menandai tahap yang SUDAH kita catat.
 * BPJS menomori tahap pelayanan 1 sampai 7 (mulai tunggu admisi, selesai
 * admisi, mulai tunggu poli, mulai layan poli, selesai layan poli, mulai
 * tunggu farmasi, selesai farmasi). Ketujuhnya bersandar pada stempel
 * waktu yang sudah ada di encounter.registrations sejak domain J item D
 * (registered_at, called_at, served_at, finished_at) dan pada resep di
 * konteks pharmacy. Menyalin waktunya ke sini akan melahirkan dua
 * kebenaran tentang kapan pasien dilayani — yang disimpan cuma KAPAN
 * TAHAP ITU DIKIRIM ke BPJS dan apa jawabannya.
 *
 * SIRANAP dipisah dari Aplicares meski keduanya melaporkan ketersediaan
 * kamar: SIRANAP milik Kemenkes dan Aplicares milik BPJS, formatnya
 * berbeda, dan keduanya bisa hidup-mati sendiri-sendiri. Menyatukan
 * riwayat pengirimannya berarti kegagalan satu pihak menyamarkan
 * keberhasilan pihak lain.
 */
return new class extends Migration
{
    private const S = 'integration';

    /** Tahap antrean menurut penomoran BPJS. */
    private const TASK = [1, 2, 3, 4, 5, 6, 7];

    public function up(): void
    {
        Schema::create(self::S . '.bpjs_queue_registrations', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Kunjungan kita. Nullable karena pendaftaran bisa datang dari
            // Mobile JKN LEBIH DULU, sebelum pasien datang dan kunjungannya
            // dibuat — dan pendaftaran yang belum bertemu kunjungannya
            // justru yang paling perlu terlihat.
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->string('registration_number', 30)->nullable();

            $table->string('booking_code', 40)->nullable()->comment('Kode booking dari Mobile JKN');
            $table->string('card_number', 20);
            $table->string('nik', 20)->nullable();
            $table->string('patient_name', 150)->nullable();

            $table->date('service_date');
            $table->string('poly_code', 20)->comment('Kode poli BPJS, dari pemetaan penjamin');
            $table->string('practitioner_code', 40)->nullable();
            $table->unsignedInteger('queue_number')->nullable();

            $table->string('status', 20)->default('terdaftar');

            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['service_date', 'poly_code']);
            $table->index('card_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_queue_registrations
            ADD CONSTRAINT bpjs_queue_status_check
            CHECK (status IN ('terdaftar','dilayani','selesai','batal'))");

        // Satu peserta tidak boleh punya dua antrean aktif pada poli dan
        // tanggal yang sama: dua nomor antrean untuk orang yang sama membuat
        // salah satunya pasti terbuang, dan pasien tidak tahu yang mana.
        DB::statement('CREATE UNIQUE INDEX bpjs_queue_peserta_aktif_unique
            ON ' . self::S . ".bpjs_queue_registrations (card_number, service_date, poly_code)
            WHERE status <> 'batal'");

        Schema::create(self::S . '.bpjs_queue_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('queue_registration_id')->constrained(self::S . '.bpjs_queue_registrations');

            $table->unsignedSmallInteger('task_id')->comment('1-7, penomoran tahap milik BPJS');

            // KAPAN TAHAP INI DIKIRIM, bukan kapan tahapnya terjadi —
            // waktunya sendiri ada di encounter.registrations.
            $table->timestampTz('sent_at');

            $table->boolean('success')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();

            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestampsTz();

            $table->index(['queue_registration_id', 'task_id']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_queue_tasks
            ADD CONSTRAINT bpjs_queue_tasks_id_check
            CHECK (task_id IN (" . implode(',', self::TASK) . '))');

        // Tahap yang sama tidak dikirim dua kali kalau sudah berhasil.
        // Pengiriman ulang tahap yang sudah sukses membuat BPJS mencatat
        // waktu pelayanan yang berubah-ubah untuk pasien yang sama.
        DB::statement('CREATE UNIQUE INDEX bpjs_queue_tasks_berhasil_unique
            ON ' . self::S . '.bpjs_queue_tasks (queue_registration_id, task_id)
            WHERE success = true');

        Schema::create(self::S . '.siranap_bed_reports', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->timestampTz('reported_at');
            $table->json('payload');

            $table->boolean('success')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();

            $table->unsignedSmallInteger('reported_classes')->default(0);

            $table->unsignedBigInteger('reported_by')->nullable();
            $table->timestampsTz();

            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.siranap_bed_reports');
        Schema::dropIfExists(self::S . '.bpjs_queue_tasks');
        Schema::dropIfExists(self::S . '.bpjs_queue_registrations');
    }
};
