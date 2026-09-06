<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan & persetujuan biaya (domain K item F) — 4 kode.
 *
 * Menaungi pengajuan_biaya, persetujuan_pengajuan_biaya,
 * validasi_persetujuan_pengajuan_biaya, dan rekap_pengajuan_biaya.
 *
 * TIGA TAHAP PERSETUJUAN, DAN ITU MEMANG DISENGAJA. Khanza memberi kode
 * terpisah untuk mengajukan, menyetujui, dan MEMVALIDASI persetujuan —
 * urutan yang di banyak sistem dianggap birokrasi berlebih lalu
 * dipangkas jadi satu tombol. Di sini dipertahankan karena ketiganya
 * memang tiga orang berbeda: yang butuh uangnya, yang berwenang
 * menyetujui, dan yang memastikan persetujuannya sah sebelum uang
 * keluar. Memangkasnya berarti satu orang bisa mengajukan dan mencairkan
 * pengeluaran rumah sakit sendirian.
 *
 * NILAI YANG DISETUJUI DISIMPAN TERPISAH dari nilai yang diajukan.
 * Penyetuju sering memotong angkanya, dan menimpa nilai pengajuan berarti
 * menghapus jejak bahwa pemotongan itu pernah terjadi — padahal selisih
 * antara yang diminta dan yang disetujui justru angka yang dicari saat
 * menyusun anggaran berikutnya.
 *
 * Realisasi TIDAK disimpan sebagai kolom di sini. Uang yang benar-benar
 * keluar dicatat sebagai transaksi kas di item A, dan pengajuan ini
 * merujuk ke transaksi itu — bukan menyimpan salinan nilainya yang bisa
 * menyimpang.
 */
return new class extends Migration
{
    private const S = 'finance';

    private const STATUS = ['diajukan', 'disetujui', 'ditolak', 'tervalidasi', 'dicairkan', 'dibatalkan'];

    public function up(): void
    {
        Schema::create(self::S . '.expense_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_number', 30)->unique();

            $table->unsignedBigInteger('unit_id')->nullable()->comment('Unit pengaju, referensi longgar');
            $table->string('unit_name', 120);

            $table->date('requested_on');
            $table->string('purpose', 200);
            $table->text('justification')->nullable();

            $table->decimal('requested_amount', 15, 2)->comment('Yang diminta pengaju');
            $table->decimal('approved_amount', 15, 2)->nullable()
                ->comment('Yang disetujui — TERPISAH, supaya pemotongannya tetap terlihat');

            $table->foreignId('category_id')->nullable()
                ->constrained(self::S . '.cash_categories')
                ->comment('Pos pengeluaran; menyambung ke pemetaan akun yang sudah ada');

            $table->string('status', 20)->default('diajukan');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_name', 120)->nullable();

            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approval_note', 200)->nullable();

            $table->timestampTz('validated_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();

            $table->string('rejection_reason', 200)->nullable();

            // Pencairan menunjuk transaksi kas yang sungguh terjadi, bukan
            // menyimpan ulang nilainya. Referensi longgar karena kas boleh
            // dibatalkan tanpa menghapus jejak pengajuannya.
            $table->unsignedBigInteger('cash_transaction_id')->nullable();
            $table->timestampTz('disbursed_at')->nullable();
            $table->unsignedBigInteger('disbursed_by')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'requested_on']);
            $table->index('unit_name');
        });

        DB::statement('ALTER TABLE ' . self::S . ".expense_requests
            ADD CONSTRAINT expense_requests_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . '.expense_requests
            ADD CONSTRAINT expense_requests_requested_positive CHECK (requested_amount > 0)');

        // Nilai yang disetujui tidak boleh melebihi yang diajukan. Kalau
        // butuh lebih, itu pengajuan baru — bukan persetujuan yang
        // diam-diam membesar di atas apa yang pernah diminta.
        DB::statement('ALTER TABLE ' . self::S . '.expense_requests
            ADD CONSTRAINT expense_requests_approved_tidak_melebihi
            CHECK (approved_amount IS NULL OR (approved_amount > 0 AND approved_amount <= requested_amount))');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.expense_requests');
    }
};
