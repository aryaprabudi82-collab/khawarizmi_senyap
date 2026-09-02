<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks integration: adapter BPJS (VClaim) dan SATUSEHAT (FHIR).
 *
 * Prinsip desain:
 *  - identity_mappings adalah SATU-SATUNYA tempat ID internal (pasien, unit,
 *    praktisi, kunjungan, diagnosis) dipetakan ke ID eksternal (Patient ID
 *    SATUSEHAT, Location ID, dst). Tidak ada modul lain yang menyimpan ID
 *    eksternal di tabelnya sendiri — kalau format ID pihak luar berubah,
 *    yang berubah cuma di sini.
 *  - outbound_messages adalah ledger idempoten setiap percobaan pengiriman,
 *    memakai pola yang sama dengan charge_lines/journal_entries: unique key
 *    memakai timestamp kejadian ASLI di sumbernya (source_event_at), bukan
 *    waktu saat sinkronisasi dijalankan, supaya retry aman dijalankan ulang
 *    berkali-kali tanpa mengirim dobel.
 *  - bpjs_sep punya siklus hidupnya sendiri (diajukan/terbit/gagal/batal)
 *    karena SEP adalah entitas bernilai bisnis sendiri (dipakai kasir dan
 *    klaim), bukan sekadar catatan "sudah dikirim atau belum" seperti
 *    resource FHIR SATUSEHAT.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.identity_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('target_system', 20)->comment('satusehat, bpjs');
            $table->string('resource_type', 30)->comment('patient, practitioner, location, encounter, condition');
            $table->string('source_context', 30)->comment('identity, organization, encounter, clinical');
            $table->unsignedBigInteger('source_id');

            $table->string('external_id', 128);
            $table->jsonb('external_meta')->nullable();

            $table->unsignedBigInteger('mapped_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('synced_at')->nullable()->comment('Null untuk pemetaan yang diisi manual, mis. praktisi/unit');

            $table->timestampsTz();

            $table->unique(['target_system', 'resource_type', 'source_context', 'source_id'], 'identity_mappings_source_unique');
            $table->index(['target_system', 'external_id']);
        });

        Schema::create(self::S . '.outbound_messages', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('target_system', 20);
            $table->string('resource_type', 30);
            $table->string('source_context', 30);
            $table->unsignedBigInteger('source_id');
            $table->timestampTz('source_event_at')->comment('Timestamp kejadian di sumbernya, bukan waktu kirim — dasar idempotensi');

            $table->string('status', 20)->default('pending');
            $table->string('http_method', 6)->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->string('external_reference', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('sent_at')->nullable();

            $table->timestampsTz();

            $table->unique(
                ['target_system', 'resource_type', 'source_context', 'source_id', 'source_event_at'],
                'outbound_messages_idempotency_key'
            );
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".outbound_messages ADD CONSTRAINT outbound_messages_status_check
            CHECK (status IN ('pending','sent','failed'))");

        Schema::create(self::S . '.bpjs_eligibility_checks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('no_kartu', 20);
            $table->date('service_date')->nullable();

            $table->boolean('is_eligible')->nullable();
            $table->string('participant_name', 150)->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampTz('checked_at');

            $table->index('no_kartu');
        });

        Schema::create(self::S . '.bpjs_sep', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id')->comment('ID registrasi encounter, referensi longgar');
            $table->string('registration_number', 24);

            $table->string('no_kartu', 20);
            $table->string('no_rujukan', 50)->nullable();
            $table->string('sep_number', 20)->nullable();

            $table->string('poli_tujuan', 10);
            $table->string('jenis_pelayanan', 1)->comment('1=rawat inap, 2=rawat jalan');
            $table->string('diagnosa_awal', 12)->nullable()->comment('Kode ICD-10');

            $table->string('status', 20)->default('diajukan');
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index('no_kartu');
            $table->index('registration_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".bpjs_sep ADD CONSTRAINT bpjs_sep_status_check
            CHECK (status IN ('diajukan','terbit','gagal','batal'))");
        DB::statement("ALTER TABLE " . self::S . ".bpjs_sep ADD CONSTRAINT bpjs_sep_jenis_pelayanan_check
            CHECK (jenis_pelayanan IN ('1','2'))");

        // Nomor SEP unik kalau sudah terbit.
        DB::statement('CREATE UNIQUE INDEX bpjs_sep_number_unique
            ON ' . self::S . '.bpjs_sep (sep_number)
            WHERE sep_number IS NOT NULL');

        /*
         * Satu kunjungan hanya boleh punya satu SEP yang masih berlaku
         * (diajukan atau terbit) di satu waktu. SEP yang batal tidak
         * menghalangi pengajuan SEP baru untuk kunjungan yang sama.
         */
        DB::statement("CREATE UNIQUE INDEX bpjs_sep_registration_active_unique
            ON " . self::S . ".bpjs_sep (registration_id)
            WHERE status IN ('diajukan','terbit')");

        Schema::create(self::S . '.satusehat_tokens', function (Blueprint $table) {
            $table->string('key', 20)->primary()->default('default');
            $table->text('access_token');
            $table->timestampTz('expires_at');
            $table->timestampTz('obtained_at');
        });

        // Kontrak baca untuk konteks lain — status SEP yang masih berlaku.
        DB::statement('CREATE VIEW ' . self::S . '.v_bpjs_sep_status AS
            SELECT registration_id, registration_number, sep_number, status, issued_at
            FROM ' . self::S . '.bpjs_sep
            WHERE status IN (\'diajukan\', \'terbit\')');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_bpjs_sep_status');

        Schema::dropIfExists(self::S . '.satusehat_tokens');
        Schema::dropIfExists(self::S . '.bpjs_sep');
        Schema::dropIfExists(self::S . '.bpjs_eligibility_checks');
        Schema::dropIfExists(self::S . '.outbound_messages');
        Schema::dropIfExists(self::S . '.identity_mappings');
    }
};
