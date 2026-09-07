<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konsultasi medik & transfer antar ruang (domain M item N).
 *
 * Menaungi konsultasi_medik, jawaban_konsultasi_medik,
 * transfer_pasien_antar_ruang, dan
 * bukti_persetujuan_transfer_pasien_antar_ruang.
 *
 * konsultasi_perawat PUNYA KODE IZIN TAPI TIDAK PUNYA TABEL di skema
 * Khanza — diperiksa, bukan diasumsikan. Karena itu tidak dibangun:
 * mengarang tabelnya berarti menebak bentuk yang bahkan Khanza sendiri
 * tidak pernah menetapkan. Kalau RSP UI memang membutuhkannya, bentuknya
 * ditentukan komite keperawatan lebih dulu.
 *
 * JAWABAN KONSULTASI TIDAK MENCATAT SIAPA YANG MENJAWAB.
 * jawaban_konsultasi_medik Khanza hanya punya empat kolom: nomor
 * permintaan, tanggal, diagnosa kerja, dan uraian jawaban. Yang tercatat
 * di sisi permintaan adalah kd_dokter_dikonsuli — siapa yang DITANYA,
 * bukan siapa yang menjawab. Dalam praktiknya keduanya sering berbeda:
 * yang menjawab konsulen yang sedang jaga. Akibatnya nasihat klinis yang
 * dijalankan dokter lain tidak bisa ditanyakan kembali kepada
 * penulisnya. Di sini penjawab dicatat sendiri dan wajib saat dijawab.
 *
 * ALAT YANG MENYERTAI PASIEN ADALAH DAFTAR, BUKAN SATU PILIHAN.
 * peralatan_yang_menyertai Khanza enum MySQL — pasien yang dipindah
 * dengan oksigen portabel sekaligus infus sekaligus kateter urin hanya
 * bisa mencatat salah satunya. Ruangan penerima membutuhkan daftar
 * lengkap alat yang menempel pada pasien, dan yang tidak tercatat tidak
 * akan dicari. Bentuknya di sini daftar berkosakata tertutup, sama
 * seperti bantuan perencanaan pemulangan pada item H.
 *
 * ASAL DAN TUJUAN RUANG DISALIN, TIDAK DIKETIK. Khanza memakai
 * varchar(30) bebas untuk asal_ruang dan ruang_selanjutnya, padahal
 * inpatient.bed_assignments sudah mencatat riwayat kamar yang
 * sebenarnya — catatan transfer yang diketik bisa menyebut bangsal yang
 * tidak ada, atau berbeda dari tempat pasien benar-benar berada.
 *
 * DIAGNOSIS DIBEKUKAN DARI REKAM MEDIS, tidak diketik untuk ketiga
 * kalinya. transfer_pasien_antar_ruang punya diagnosa_utama varchar(100)
 * dan diagnosa_sekunder varchar(150) — satu kolom untuk SELURUH
 * diagnosis sekunder sekaligus. Aturan yang sama dengan resume medis
 * pada item H: disalin apa adanya dari clinical.diagnoses.
 *
 * TANDA VITAL SEBELUM DAN SESUDAH TRANSFER TIDAK DIDUPLIKASI di sini —
 * panel observasi sejak item D sudah menanganinya, dan salinan kedua
 * adalah dua tekanan darah yang bisa berbeda pada menit yang sama.
 *
 * PENYERAH DAN PENERIMA KEDUANYA WAJIB. Transfer adalah serah terima;
 * yang tercatat hanya sepihak bukan serah terima melainkan kepindahan
 * yang kebetulan diketahui satu orang.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createConsultations();
        $this->createTransfers();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.patient_transfers');
        Schema::dropIfExists(self::S.'.medical_consultations');
    }

    private function createConsultations(): void
    {
        Schema::create(self::S.'.medical_consultations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('request_number', 30)->unique();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('requested_at');
            $table->string('kind', 30)
                ->comment('konsultasi, evaluasi, rawat-bersama, alih-rawat, pre-post-operasi');
            $table->string('urgency', 20)->default('biasa')
                ->comment('biasa, segera, cito — tidak ada di Khanza; konsultasi cito yang tidak dibedakan akan mengantre di belakang yang rutin');

            $table->unsignedBigInteger('requesting_practitioner_id')->nullable();
            $table->string('requesting_practitioner_name', 150);
            $table->unsignedBigInteger('consulted_practitioner_id')->nullable();
            $table->string('consulted_practitioner_name', 150)->nullable()
                ->comment('Siapa yang DITANYA — belum tentu yang menjawab');
            $table->string('consulted_specialty', 100)->nullable();

            $table->string('working_diagnosis', 200)->nullable();
            $table->text('question');

            // Jawaban menumpang di baris yang sama: satu permintaan satu
            // jawaban, dan tabel berelasi satu-ke-satu adalah satu tempat
            // lagi yang bisa tidak sinkron.
            $table->timestampTz('answered_at')->nullable();
            $table->unsignedBigInteger('answering_practitioner_id')->nullable();
            $table->string('answering_practitioner_name', 150)->nullable()
                ->comment('Siapa yang BENAR-BENAR menjawab — tidak ada di Khanza');
            $table->string('answer_diagnosis', 200)->nullable();
            $table->text('answer')->nullable();

            $table->string('status', 20)->default('terbuka');
            $table->string('cancellation_reason', 255)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'requested_at']);
            $table->index(['status', 'requested_at']);
            $table->index(['patient_id', 'requested_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".medical_consultations
            ADD CONSTRAINT medical_consultations_kind_check
            CHECK (kind IN ('konsultasi','evaluasi','rawat-bersama','alih-rawat','pre-post-operasi'))");

        DB::statement('ALTER TABLE '.self::S.".medical_consultations
            ADD CONSTRAINT medical_consultations_urgency_check
            CHECK (urgency IN ('biasa','segera','cito'))");

        DB::statement('ALTER TABLE '.self::S.".medical_consultations
            ADD CONSTRAINT medical_consultations_status_check
            CHECK (status IN ('terbuka','dijawab','dibatalkan'))");

        // Jawaban wajib menyebut penjawabnya — inti pengetatan item ini.
        DB::statement('ALTER TABLE '.self::S.".medical_consultations
            ADD CONSTRAINT medical_consultations_answered_check
            CHECK (status <> 'dijawab'
                   OR (answered_at IS NOT NULL
                       AND answer IS NOT NULL AND btrim(answer) <> ''
                       AND answering_practitioner_name IS NOT NULL))");

        DB::statement('ALTER TABLE '.self::S.'.medical_consultations
            ADD CONSTRAINT medical_consultations_order_check
            CHECK (answered_at IS NULL OR answered_at >= requested_at)');
    }

    private function createTransfers(): void
    {
        Schema::create(self::S.'.patient_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('admission_id')->nullable();
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('transferred_at');

            // Disalin dari kontrak admisi, tidak diketik — lihat catatan kelas.
            $table->string('from_room', 60)->nullable();
            $table->string('from_unit_name', 120)->nullable();
            $table->string('to_room', 60)->nullable();
            $table->string('to_unit_name', 120)->nullable();

            // Salinan beku dari clinical.diagnoses, berapa pun jumlahnya.
            $table->jsonb('diagnoses')->default(DB::raw("'[]'::jsonb"));

            $table->string('indication', 40)
                ->comment('kondisi-stabil, kondisi-tetap, kondisi-memburuk, fasilitas-kurang, butuh-fasilitas-lebih, butuh-tenaga-lebih-ahli, tenaga-kurang, lain-lain');
            $table->string('indication_note', 200)->nullable();

            $table->text('procedures_done')->nullable();
            $table->text('medication_given')->nullable();

            $table->string('transport_method', 30)->nullable()
                ->comment('kursi-roda, tempat-tidur, brankar, jalan-sendiri');

            // DAFTAR, bukan satu pilihan — lihat catatan kelas.
            $table->jsonb('accompanying_equipment')->default(DB::raw("'[]'::jsonb"));
            $table->string('equipment_note', 200)->nullable();

            // TIDAK ADA KOLOM TANDA VITAL SEBELUM/SESUDAH TRANSFER.

            $table->unsignedBigInteger('handed_over_by')->nullable();
            $table->string('handed_over_by_name', 150);
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 150);

            $table->string('consent_given_by', 150)->nullable()
                ->comment('Keluarga yang menyetujui pemindahan — bukti_persetujuan_transfer_pasien_antar_ruang');
            $table->string('consent_relation', 60)->nullable();

            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['registration_id', 'transferred_at']);
            $table->index(['patient_id', 'transferred_at']);
            $table->index('admission_id');
        });

        DB::statement('ALTER TABLE '.self::S.".patient_transfers
            ADD CONSTRAINT patient_transfers_indication_check
            CHECK (indication IN ('kondisi-stabil','kondisi-tetap','kondisi-memburuk',
                                  'fasilitas-kurang','butuh-fasilitas-lebih',
                                  'butuh-tenaga-lebih-ahli','tenaga-kurang','lain-lain'))");

        DB::statement('ALTER TABLE '.self::S.".patient_transfers
            ADD CONSTRAINT patient_transfers_transport_check
            CHECK (transport_method IS NULL
                   OR transport_method IN ('kursi-roda','tempat-tidur','brankar','jalan-sendiri'))");

        DB::statement('ALTER TABLE '.self::S.".patient_transfers
            ADD CONSTRAINT patient_transfers_equipment_check
            CHECK (jsonb_typeof(accompanying_equipment) = 'array')");

        // Alasan "lain-lain" tanpa keterangan adalah indikasi yang tidak
        // menjelaskan apa pun, dan indikasi pemindahan pasien justru yang
        // ditinjau saat mutu perawatan dipertanyakan.
        DB::statement('ALTER TABLE '.self::S.".patient_transfers
            ADD CONSTRAINT patient_transfers_other_reason_check
            CHECK (indication <> 'lain-lain'
                   OR (indication_note IS NOT NULL AND btrim(indication_note) <> ''))");
    }
};
