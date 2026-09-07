<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mandiri Inhealth (domain L item Q).
 *
 * Menaungi inhealth_cek_eligibilitas, inhealth_sjp, dan
 * inhealth_kirim_tagihan. Tujuh kode pemetaan (dokter, poli, dan lima
 * tarif tindakan) serta tiga kode referensi memakai mekanisme pemetaan dan
 * referensi kode penjamin dari item E — jenis pemetaan baru, bukan tabel
 * baru.
 *
 * SJP ADALAH PADANAN SEP DI SISI INHEALTH: Surat Jaminan Pelayanan yang
 * menyatakan penjamin menanggung kunjungan ini. Karena itu bentuknya
 * mengikuti bpjs_sep — termasuk aturan yang paling penting: SATU KUNJUNGAN
 * HANYA BOLEH PUNYA SATU SJP BERLAKU. Dua surat jaminan atas satu
 * kunjungan akan ditagihkan dua kali, dan salah satunya pasti ditolak.
 *
 * ELIGIBILITAS PUNYA TABELNYA SENDIRI, sama seperti sisi BPJS. Alasannya
 * bukan kerapian melainkan kegunaan: pertanyaan "kenapa SJP tidak terbit"
 * hanya bisa dijawab kalau pemeriksaan yang GAGAL pun meninggalkan jejak.
 *
 * TAGIHAN MENUMPANG DI BARIS SJP, bukan di tabel tersendiri — dan itu
 * keputusan yang disengaja. Di Inhealth, tagihan diajukan ATAS SJP, satu
 * banding satu. Tabel terpisah dengan relasi satu-ke-satu adalah tabel
 * yang lahir hanya untuk menampung kolom, dan ia menambah satu tempat lagi
 * yang bisa tidak sinkron tanpa menambah satu pun kebenaran.
 */
return new class extends Migration
{
    private const S = 'integration';

    public function up(): void
    {
        Schema::create(self::S . '.inhealth_eligibility_checks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('member_number', 30);
            $table->date('service_date');

            // null berarti PEMERIKSAANNYA GAGAL, bukan "tidak eligible" —
            // dua keadaan yang menuntut tindakan berbeda dari petugas.
            $table->boolean('is_eligible')->nullable();

            $table->string('member_name', 150)->nullable();
            $table->string('plan_name', 100)->nullable()->comment('Produk/plan Inhealth peserta');
            $table->json('response_payload')->nullable();
            $table->string('error_message', 300)->nullable();

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampsTz();

            $table->index(['member_number', 'service_date']);
        });

        Schema::create(self::S . '.inhealth_guarantees', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->string('registration_number', 30);
            $table->string('member_number', 30);
            $table->string('patient_name', 150)->nullable();

            // Dari Inhealth, bukan dinomori sendiri — nomor karangan tidak
            // akan dikenali saat tagihannya diajukan.
            $table->string('sjp_number', 30)->nullable();

            $table->string('service_type', 10)->default('ralan')->comment('ralan atau ranap');
            $table->string('poli_code', 20)->nullable();
            $table->string('diagnosis_code', 20)->nullable();

            $table->string('status', 20)->default('diajukan');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_message', 300)->nullable();

            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();

            // Tagihan menumpang di sini — lihat catatan di atas.
            $table->string('billing_status', 20)->nullable();
            $table->decimal('billed_amount', 15, 2)->nullable();
            $table->timestampTz('billed_at')->nullable();
            $table->json('billing_items')->nullable()->comment('Rincian tagihan, dibekukan saat diajukan');
            $table->json('billing_response')->nullable();
            $table->string('billing_message', 300)->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampsTz();

            $table->index(['status', 'requested_at']);
            $table->index('member_number');
            $table->index('registration_id');
        });

        DB::statement('ALTER TABLE ' . self::S . ".inhealth_guarantees
            ADD CONSTRAINT inhealth_guarantees_status_check
            CHECK (status IN ('diajukan','terbit','gagal','batal'))");

        DB::statement('ALTER TABLE ' . self::S . ".inhealth_guarantees
            ADD CONSTRAINT inhealth_guarantees_service_type_check
            CHECK (service_type IN ('ralan','ranap'))");

        DB::statement('ALTER TABLE ' . self::S . ".inhealth_guarantees
            ADD CONSTRAINT inhealth_guarantees_billing_status_check
            CHECK (billing_status IS NULL OR billing_status IN ('diajukan','diterima','ditolak'))");

        // Tagihan tidak boleh ada tanpa SJP yang terbit: menagih atas surat
        // jaminan yang tidak pernah terbit adalah menagih tanpa dasar.
        DB::statement('ALTER TABLE ' . self::S . ".inhealth_guarantees
            ADD CONSTRAINT inhealth_guarantees_tagihan_check
            CHECK (billing_status IS NULL OR status = 'terbit')");

        // Satu kunjungan, satu SJP berlaku — pola yang sama seperti bpjs_sep.
        DB::statement('CREATE UNIQUE INDEX inhealth_guarantee_aktif_unique
            ON ' . self::S . ".inhealth_guarantees (registration_id)
            WHERE status IN ('diajukan','terbit')");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.inhealth_guarantees');
        Schema::dropIfExists(self::S . '.inhealth_eligibility_checks');
    }
};
