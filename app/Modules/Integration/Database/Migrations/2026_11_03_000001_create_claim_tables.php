<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klaim INA-CBG & monitoring klaim BPJS (domain L item C) — 10 kode.
 *
 * Menaungi inacbg_klaim_baru_manual, inacbg_klaim_baru_manual2,
 * inacbg_klaim_baru_otomatis, inacbg_coder_nik, bridging_smart_klaim_bpjs,
 * mapping_penyakit_smart_klaim_bpjs, mapping_prosedur_smart_klaim_bpjs,
 * bpjs_monitoring_klaim, bpjs_monitoring_klaim_apotek, dan
 * bpjs_klaim_jasa_raharja.
 *
 * BATAS YANG PALING PENTING: KODE CBG DAN TARIFNYA DITERIMA, BUKAN
 * DIHITUNG. Grouper INA-CBG adalah aplikasi terpisah milik Kemenkes
 * (E-Klaim); SIMRS mengirimkan data klaim ke sana dan menerima kembali
 * kode CBG beserta tarifnya. Menghitung sendiri kode CBG berarti
 * mengarang tarif klaim — angka yang akan dibayarkan negara, dan
 * kesalahannya bukan sekadar laporan yang meleset. Kolom cbg_code dan
 * cbg_tariff di sini SELALU berasal dari jawaban grouper, dan kosong
 * selama belum dikirim.
 *
 * SATU KLAIM PER KUNJUNGAN, dan itu dijaga indeks unik parsial: klaim
 * yang dibatalkan boleh diganti, tapi dua klaim aktif atas kunjungan yang
 * sama akan dibayar dua kali atau ditolak dua-duanya.
 *
 * ISI KLAIM DIBEKUKAN SAAT DIKIRIM. Diagnosis dan tindakan yang dikirim
 * ke grouper disimpan sebagai salinan pada baris klaimnya, bukan dibaca
 * ulang dari rekam medis setiap kali. Rekam medis boleh dikoreksi setelah
 * klaim terkirim — dan kalau isinya dibaca ulang, klaim yang sudah
 * diverifikasi BPJS berubah isinya tanpa ada yang menyentuhnya.
 *
 * MONITORING TIDAK MENGUBAH STATUS KLAIM SENDIRI. Jawaban BPJS disimpan
 * apa adanya sebagai salinan; status di sini tetap status pengiriman kita.
 * Menyamakan keduanya berarti kehilangan kemampuan membedakan "belum kami
 * kirim" dari "sudah kami kirim tapi BPJS belum memverifikasi".
 */
return new class extends Migration
{
    private const S = 'integration';

    private const STATUS = ['draf', 'terkirim', 'dikembalikan', 'terverifikasi', 'ditolak', 'batal'];

    private const JENIS = ['inacbg', 'smart-klaim', 'jasa-raharja', 'apotek'];

    public function up(): void
    {
        Schema::create(self::S . '.claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('claim_number', 30)->unique()->comment('Nomor internal kita; nomor BPJS disimpan terpisah');

            $table->string('claim_type', 20)->default('inacbg');

            $table->unsignedBigInteger('registration_id');
            $table->string('registration_number', 30);
            $table->unsignedBigInteger('patient_id');
            $table->string('patient_name', 150);
            $table->string('card_number', 20)->nullable();
            $table->string('sep_number', 30)->nullable();

            $table->string('care_type', 10)->comment('ralan / ranap');
            $table->date('admitted_on');
            $table->date('discharged_on')->nullable();

            // Isi klaim, DIBEKUKAN saat dikirim. Salinan, bukan bacaan ulang.
            $table->json('diagnoses')->nullable();
            $table->json('procedures')->nullable();
            $table->decimal('hospital_charge', 15, 2)->default(0)->comment('Total biaya menurut kita');

            // DITERIMA dari grouper, tidak pernah dihitung sendiri.
            $table->string('cbg_code', 20)->nullable();
            $table->string('cbg_description', 200)->nullable();
            $table->decimal('cbg_tariff', 15, 2)->nullable()->comment('Tarif dari grouper INA-CBG');

            $table->string('status', 20)->default('draf');

            $table->timestampTz('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('grouper_response')->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 200)->nullable();

            $table->timestampsTz();

            $table->index(['status', 'admitted_on']);
            $table->index('sep_number');
        });

        DB::statement('ALTER TABLE ' . self::S . ".claims
            ADD CONSTRAINT claims_status_check
            CHECK (status IN ('" . implode("','", self::STATUS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".claims
            ADD CONSTRAINT claims_type_check
            CHECK (claim_type IN ('" . implode("','", self::JENIS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . '.claims
            ADD CONSTRAINT claims_tarif_tidak_negatif
            CHECK (cbg_tariff IS NULL OR cbg_tariff >= 0)');

        // Satu klaim aktif per kunjungan. Yang dibatalkan boleh diganti —
        // pola indeks parsial yang sama seperti tagihan yang di-void.
        DB::statement('CREATE UNIQUE INDEX claims_registrasi_aktif_unique
            ON ' . self::S . ".claims (registration_id, claim_type)
            WHERE status <> 'batal'");

        Schema::create(self::S . '.claim_monitorings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('scope', 20)->comment('rs / apotek — monitoring klaim RS atau klaim apotek');
            $table->date('period_from');
            $table->date('period_until');

            $table->boolean('success')->default(false);
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();

            // Jawaban BPJS disimpan APA ADANYA. Tidak dipakai mengubah
            // status klaim kita — lihat catatan di atas.
            $table->json('raw_response')->nullable();

            $table->unsignedInteger('claim_count')->default(0);
            $table->decimal('total_tariff', 18, 2)->default(0);

            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestampsTz();

            $table->index(['scope', 'period_from']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".claim_monitorings
            ADD CONSTRAINT claim_monitorings_scope_check
            CHECK (scope IN ('rs','apotek'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.claim_monitorings');
        Schema::dropIfExists(self::S . '.claims');
    }
};
