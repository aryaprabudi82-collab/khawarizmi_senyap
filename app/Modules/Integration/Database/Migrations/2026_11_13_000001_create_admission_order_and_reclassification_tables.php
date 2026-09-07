<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surat Perintah Rawat Inap & reklasifikasi SEP (domain L item L).
 *
 * Menaungi bpjs_surat_pri, reklasifikasi_ralan, dan reklasifikasi_ranap.
 *
 * SURAT PRI (Perintah Rawat Inap) adalah perintah dokter kita agar peserta
 * dirawat inap tanpa melalui rujukan baru — dasar terbitnya SEP rawat inap.
 * NOMORNYA DARI BPJS, bukan dinomori sendiri: nomor karangan tidak akan
 * dikenali saat SEP-nya diterbitkan, dan pasien yang sudah masuk bangsal
 * terlanjur dirawat tanpa penjaminan.
 *
 * REKLASIFIKASI ADALAH PERUBAHAN APA YANG DITAGIHKAN, jadi tidak boleh
 * menimpa diam-diam. Pasien yang mendaftar rawat jalan lalu diputuskan
 * dirawat inap harus berpindah kelas pelayanan pada SEP-nya, dan itu
 * mengubah tarif yang diklaim ke negara. Karena itu yang lama DISIMPAN
 * berikut alasan dan pelakunya: perubahan tagihan yang tidak meninggalkan
 * jejak tidak bisa dibedakan dari kecurangan, sekalipun niatnya benar.
 *
 * REKLASIFIKASI HANYA UNTUK SEP YANG BELUM DIKLAIM. Setelah klaim dikirim,
 * isinya sudah dibekukan (item C) dan yang berubah harus lewat revisi
 * klaim — bukan lewat suntingan senyap pada SEP yang klaimnya sedang
 * diverifikasi BPJS.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.bpjs_admission_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);
            $table->string('card_number', 20);

            $table->unsignedBigInteger('registration_id')->nullable()
                ->comment('Kunjungan rawat jalan tempat perintah ini dibuat');

            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150)->nullable();
            $table->string('poli_code', 20)->nullable();

            $table->date('planned_date')->comment('Rencana tanggal masuk rawat inap');
            $table->string('diagnosis_code', 20)->nullable();
            $table->string('reason', 300)->nullable();

            // Dari BPJS, bukan dinomori sendiri — lihat catatan di atas.
            $table->string('order_number', 40)->nullable();

            $table->string('status', 20)->default('terbit');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('raw_response')->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'planned_date']);
            $table->index('card_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_admission_orders
            ADD CONSTRAINT bpjs_admission_orders_status_check
            CHECK (status IN ('terbit','gagal','batal','terpakai'))");

        // Satu kunjungan hanya boleh punya satu surat PRI berlaku: dua
        // perintah rawat inap atas kunjungan yang sama menerbitkan dua SEP
        // ranap, dan salah satunya pasti ditolak saat klaim.
        DB::statement('CREATE UNIQUE INDEX bpjs_admission_order_aktif_unique
            ON ' . self::S . ".bpjs_admission_orders (registration_id)
            WHERE status IN ('terbit','terpakai') AND registration_id IS NOT NULL");

        Schema::create(self::S . '.sep_reclassifications', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sep_id');
            $table->string('sep_number', 30);
            $table->unsignedBigInteger('registration_id')->nullable();

            // Yang lama DISIMPAN, bukan ditimpa — lihat catatan di atas.
            $table->string('previous_service_type', 5);
            $table->string('new_service_type', 5);
            $table->string('previous_class', 10)->nullable();
            $table->string('new_class', 10)->nullable();

            $table->string('reason', 300);

            $table->string('status', 20)->default('diajukan');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('raw_response')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index('sep_id');
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".sep_reclassifications
            ADD CONSTRAINT sep_reclassifications_status_check
            CHECK (status IN ('diajukan','diterima','gagal'))");

        // Reklasifikasi ke jenis yang sama bukan reklasifikasi — itu cuma
        // baris tambahan yang mengaburkan riwayat perubahan tagihan.
        DB::statement('ALTER TABLE ' . self::S . ".sep_reclassifications
            ADD CONSTRAINT sep_reclassifications_berbeda_check
            CHECK (previous_service_type <> new_service_type
                   OR previous_class IS DISTINCT FROM new_class)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.sep_reclassifications');
        Schema::dropIfExists(self::S . '.bpjs_admission_orders');
    }
};
