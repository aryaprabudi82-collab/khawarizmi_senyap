<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rujukan & surat kontrol BPJS (domain L item A) — 18 kode.
 *
 * Menaungi bpjs_cek_nomor_rujukan, bpjs_cek_nomor_rujukan_rs,
 * bpjs_cek_rujukan_kartu_pcare, bpjs_cek_rujukan_kartu_rs,
 * bpjs_cek_tanggal_rujukan, bpjs_cek_riwayat_rujukanrs, bpjs_surat_kontrol,
 * bpjs_rujukan_keluar, bpjs_rujukan_khusus, dan bpjs_cek_sep.
 *
 * ENAM CARA MENCARI, SATU BENTUK JAWABAN. Khanza memberi menu terpisah
 * untuk tiap kunci pencarian rujukan; yang berbeda cuma kuncinya, yang
 * dikembalikan rujukan yang sama. Satu tabel hasil pencarian, dibedakan
 * kolom cara pencariannya.
 *
 * HASIL PENCARIAN DISIMPAN, dan itu disengaja. Rujukan yang sudah dipakai
 * menerbitkan SEP harus tetap bisa ditelusuri meski VClaim berubah atau
 * sedang gangguan — kalau hasilnya cuma ditampilkan lalu dibuang, tidak
 * ada cara membuktikan apa yang dilihat petugas saat itu. Yang disimpan
 * adalah SALINAN JAWABAN BPJS, bukan kebenaran kita sendiri: kolomnya
 * diberi nama apa adanya dan tidak pernah dipakai menggantikan data
 * pasien di konteks identity.
 *
 * SURAT KONTROL menyimpan nomor dari BPJS, bukan menomori sendiri. Nomor
 * yang kita karang tidak akan dikenali BPJS saat pasien datang kontrol.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.bpjs_referral_lookups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('source', 10)->comment('pcare / rs — asal rujukannya');
            $table->string('searched_by', 10)->comment('nomor / kartu / tanggal');
            $table->string('search_key', 40);

            $table->boolean('found')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 200)->nullable();

            // Salinan jawaban BPJS. Disimpan apa adanya untuk penelusuran;
            // tidak pernah dipakai menggantikan data pasien kita sendiri.
            $table->string('referral_number', 40)->nullable();
            $table->date('referral_date')->nullable();
            $table->string('card_number', 20)->nullable();
            $table->string('member_name', 150)->nullable();
            $table->string('referring_facility', 150)->nullable();
            $table->string('diagnosis', 200)->nullable();
            $table->string('target_poly', 40)->nullable();
            $table->json('raw_response')->nullable();

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampsTz();

            $table->index(['search_key', 'created_at']);
            $table->index('referral_number');
        });

        Schema::create(self::S . '.bpjs_control_letters', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Nomor dari BPJS, bukan nomor kita. Nullable karena penerbitan
            // bisa gagal, dan kegagalannya tetap perlu tercatat.
            $table->string('letter_number', 40)->nullable();

            $table->unsignedBigInteger('registration_id')->nullable()->comment('Kunjungan asal, referensi longgar');
            $table->string('sep_number', 30)->comment('SEP asal — VClaim menolak surat kontrol tanpa ini');
            $table->string('card_number', 20);
            $table->string('member_name', 150)->nullable();

            $table->date('planned_date')->comment('Tanggal rencana kontrol');
            $table->string('target_poly', 40)->nullable();
            $table->string('practitioner_code', 40)->nullable();
            $table->string('note', 200)->nullable();

            $table->string('status', 20)->default('gagal');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 200)->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 200)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['sep_number', 'status']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_control_letters
            ADD CONSTRAINT bpjs_control_letters_status_check
            CHECK (status IN ('terbit','gagal','batal'))");

        // Satu SEP hanya boleh punya satu surat kontrol yang masih berlaku.
        // Penerbitan ganda membuat pasien punya dua jadwal kontrol yang
        // sama-sama sah di mata BPJS, dan salah satunya pasti terbuang.
        DB::statement('CREATE UNIQUE INDEX bpjs_control_letters_sep_aktif_unique
            ON ' . self::S . ".bpjs_control_letters (sep_number)
            WHERE status = 'terbit' AND cancelled_at IS NULL");

        Schema::create(self::S . '.bpjs_outgoing_referrals', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('referral_number', 40)->nullable()->comment('Nomor dari BPJS');

            $table->unsignedBigInteger('registration_id')->nullable();
            $table->string('sep_number', 30);
            $table->string('card_number', 20);

            $table->date('referral_date');
            $table->string('target_facility_code', 40);
            $table->string('target_facility_name', 150)->nullable();
            $table->string('target_service', 40)->nullable()->comment('Poli atau jenis pelayanan tujuan');
            $table->string('diagnosis_code', 20)->nullable();
            $table->text('reason')->nullable();

            // rujukan_khusus: rujukan untuk penyakit kronis tertentu yang
            // aturannya berbeda. Dibedakan kolom, bukan tabel tersendiri —
            // yang berbeda cuma jenisnya, bukan bentuk datanya.
            $table->boolean('is_special')->default(false);

            $table->string('status', 20)->default('gagal');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 200)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['sep_number', 'status']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_outgoing_referrals
            ADD CONSTRAINT bpjs_outgoing_referrals_status_check
            CHECK (status IN ('terbit','gagal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.bpjs_outgoing_referrals');
        Schema::dropIfExists(self::S . '.bpjs_control_letters');
        Schema::dropIfExists(self::S . '.bpjs_referral_lookups');
    }
};
