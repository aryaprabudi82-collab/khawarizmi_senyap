<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aplicares & iCare BPJS (domain L item B) — 3 kode.
 *
 * Menaungi aplicare_referensi_kamar, aplicare_ketersediaan_kamar, dan
 * riwayat_perawatan_icare_bpjs.
 *
 * APLICARES adalah kewajiban pelaporan, bukan sekadar fitur. Rumah sakit
 * wajib melaporkan ketersediaan tempat tidur ke BPJS secara berkala
 * supaya calon pasien bisa melihatnya sebelum datang. Angka yang basi di
 * sana mengirim pasien ke rumah sakit yang sebenarnya sudah penuh.
 *
 * KETERSEDIAAN TIDAK DISIMPAN ULANG DI SINI. Jumlah tempat tidur terisi
 * dan kosong sudah dihitung inpatient.v_bed_availability sejak domain J
 * item C; menyalinnya ke tabel integrasi berarti dua angka ketersediaan
 * yang bisa berbeda, dan yang dikirim ke BPJS justru yang salah. Yang
 * disimpan di sini cuma RIWAYAT PENGIRIMAN: apa yang dikirim, kapan, dan
 * apa jawaban BPJS.
 *
 * PEMETAAN KELAS KAMAR DIPERLUKAN karena kode kelas Aplicares ditetapkan
 * BPJS dan tidak sama dengan kode kelas kita. Pemetaannya dibiarkan
 * KOSONG sampai diisi petugas: menebak kode kelas BPJS akan melaporkan
 * tempat tidur VIP sebagai kelas 3, dan kesalahan itu langsung terlihat
 * publik di aplikasi Mobile JKN.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.aplicares_room_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('room_class', 30)->unique()->comment('Kelas kamar kita, dari inpatient');
            $table->string('bpjs_class_code', 20)->nullable()->comment('Kode kelas Aplicares; null = belum dipetakan');
            $table->string('bpjs_class_name', 80)->nullable();
            $table->boolean('is_reported')->default(true)->comment('Ikut dilaporkan ke BPJS atau tidak');

            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestampsTz();
        });

        Schema::create(self::S . '.aplicares_bed_reports', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->timestampTz('reported_at');

            // Apa yang DIKIRIM, disimpan apa adanya. Bukan sumber kebenaran
            // ketersediaan — itu tetap inpatient.v_bed_availability.
            $table->json('payload');

            $table->boolean('success')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 200)->nullable();

            $table->unsignedSmallInteger('mapped_classes')->default(0)->comment('Berapa kelas yang ikut terkirim');
            $table->unsignedSmallInteger('unmapped_classes')->default(0)->comment('Berapa kelas yang terlewat karena belum dipetakan');

            $table->unsignedBigInteger('reported_by')->nullable();
            $table->timestampsTz();

            $table->index('reported_at');
        });

        Schema::create(self::S . '.icare_history_lookups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('card_number', 20);
            $table->unsignedBigInteger('registration_id')->nullable();

            $table->boolean('found')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 200)->nullable();

            // Riwayat perawatan peserta menurut BPJS — data pihak lain,
            // disimpan sebagai salinan untuk penelusuran dan TIDAK pernah
            // dipakai menggantikan rekam medis kita.
            $table->json('raw_response')->nullable();

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampsTz();

            $table->index(['card_number', 'created_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . '.aplicares_bed_reports
            ADD CONSTRAINT aplicares_bed_reports_kelas_tidak_negatif
            CHECK (mapped_classes >= 0 AND unmapped_classes >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.icare_history_lookups');
        Schema::dropIfExists(self::S . '.aplicares_bed_reports');
        Schema::dropIfExists(self::S . '.aplicares_room_mappings');
    }
};
