<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sisrute — Sistem Rujukan Terintegrasi Kemenkes (domain L item P).
 *
 * Menaungi sisrute_rujukan_keluar dan sisrute_rujukan_masuk. Tiga kode
 * referensinya (alasan rujuk, diagnosa, faskes) memakai mekanisme daftar
 * referensi sistem luar dari item E — daftar milik sistem lain yang kita
 * salin dan segarkan seluruhnya, persis seperti referensi VClaim.
 *
 * BEDANYA DENGAN RUJUKAN BPJS: rujukan BPJS bersifat administratif dan
 * finansial — ia menentukan siapa yang membayar. Sisrute bersifat KLINIS
 * dan DUA ARAH: rumah sakit perujuk menyampaikan kondisi pasien, dan rumah
 * sakit tujuan menjawab sanggup atau tidak menerimanya. Yang dipertaruhkan
 * bukan klaim, melainkan pasien yang sedang menunggu tempat.
 *
 * DUA ARAH, DUA SIFAT DATA YANG BERBEDA — dan itu yang menentukan bentuk
 * tabel ini:
 *
 * - RUJUKAN KELUAR TIDAK DISIMPAN ULANG di sini. Isinya sudah ada di
 *   encounter.outgoing_referrals; yang dicatat cuma PENGIRIMANNYA dan
 *   jawaban rumah sakit tujuan. Menyalin isinya melahirkan dua sumber
 *   kebenaran untuk satu rujukan yang sama, dan yang dikirim ke Sisrute
 *   justru bisa jadi yang salah.
 *
 * - RUJUKAN MASUK ADALAH DATA BARU, dan memang harus punya tempat sendiri.
 *   Ia permintaan dari rumah sakit lain yang belum punya padanan apa pun
 *   dalam catatan kita: pasiennya belum terdaftar, belum tentu datang, dan
 *   mungkin kita tolak. Ketidaksimetrisan ini disengaja, bukan kelalaian.
 *
 * MENERIMA RUJUKAN BUKAN MENDAFTARKAN PASIEN. Menerima berarti kita
 * menyanggupi tempat; pendaftaran terjadi saat pasiennya benar-benar tiba.
 * Menyatukan keduanya akan melahirkan kunjungan atas pasien yang tidak
 * pernah datang — dan kunjungan itu ikut terhitung di sensus harian.
 */
return new class extends Migration
{
    private const S = 'integration';

    private const ARAH = ['keluar', 'masuk'];

    private const STATUS = [
        'diajukan',    // sudah dikirim / diterima, menunggu jawaban
        'diterima',    // rumah sakit tujuan sanggup
        'ditolak',     // tidak sanggup, wajib beralasan
        'dibatalkan',  // ditarik perujuk
        'tiba',        // pasiennya benar-benar datang
        'gagal',       // panggilan ke Sisrute gagal
    ];

    public function up(): void
    {
        Schema::create(self::S . '.sisrute_referrals', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('direction', 10);

            // Nomor dari Sisrute — bukan dinomori sendiri.
            $table->string('sisrute_number', 40)->nullable();

            // Hanya untuk arah KELUAR: menunjuk rujukan yang isinya sudah
            // tersimpan di konteks encounter. Tanpa foreign key lintas
            // schema; batas konteks dijaga kontrak view, bukan FK.
            $table->unsignedBigInteger('outgoing_referral_id')->nullable();

            // Hanya untuk arah MASUK: identitas pasien menurut RUMAH SAKIT
            // PERUJUK. Disimpan apa adanya dan TIDAK pernah dipakai membuat
            // pasien di master kita — pasien baru dibuat saat ia tiba dan
            // identitasnya diperiksa langsung.
            $table->string('patient_name', 150)->nullable();
            $table->string('patient_identity_number', 30)->nullable();
            $table->date('patient_birth_date')->nullable();
            $table->string('patient_sex', 1)->nullable();

            $table->string('origin_facility_code', 40)->nullable();
            $table->string('origin_facility_name', 150)->nullable();
            $table->string('destination_facility_code', 40)->nullable();
            $table->string('destination_facility_name', 150)->nullable();

            $table->string('reason_code', 20)->nullable()->comment('Kode alasan rujuk Sisrute');
            $table->string('reason_note', 300)->nullable();
            $table->string('diagnosis_code', 20)->nullable();
            $table->string('diagnosis_note', 300)->nullable();
            $table->text('clinical_summary')->nullable()->comment('Kondisi pasien menurut perujuk');

            $table->string('status', 20)->default('diajukan');
            $table->timestampTz('requested_at');
            $table->timestampTz('responded_at')->nullable();

            // Penolakan WAJIB beralasan — lihat catatan pada service.
            $table->string('rejection_reason', 300)->nullable();
            $table->string('responded_by_name', 150)->nullable();

            // Diisi hanya kalau pasiennya benar-benar tiba dan didaftarkan.
            $table->unsignedBigInteger('registration_id')->nullable();

            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('raw_response')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['direction', 'status', 'requested_at']);
            $table->index('outgoing_referral_id');
            $table->index('sisrute_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".sisrute_referrals
            ADD CONSTRAINT sisrute_referrals_direction_check
            CHECK (direction IN ('" . implode("','", self::ARAH) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".sisrute_referrals
            ADD CONSTRAINT sisrute_referrals_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        // Penolakan tanpa alasan meninggalkan rumah sakit perujuk mencari
        // buta sementara pasiennya menunggu. Ditegakkan basis data, bukan
        // cuma service.
        DB::statement('ALTER TABLE ' . self::S . ".sisrute_referrals
            ADD CONSTRAINT sisrute_referrals_alasan_tolak_check
            CHECK (status <> 'ditolak' OR rejection_reason IS NOT NULL)");

        // Satu rujukan keluar hanya boleh punya satu pengajuan Sisrute yang
        // masih berjalan: dua pengajuan atas rujukan yang sama membuat dua
        // rumah sakit menyiapkan tempat untuk satu pasien.
        DB::statement('CREATE UNIQUE INDEX sisrute_rujukan_keluar_aktif_unique
            ON ' . self::S . ".sisrute_referrals (outgoing_referral_id)
            WHERE outgoing_referral_id IS NOT NULL
              AND status IN ('diajukan','diterima')");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.sisrute_referrals');
    }
};
