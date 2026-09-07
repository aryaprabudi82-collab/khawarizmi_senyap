<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anestesi & pasca-operasi (domain M item M).
 *
 * Menaungi catatan_anestesi_sedasi, penilaian_pre_anestesi,
 * penilaian_pre_induksi, catatan_pengkajian_paska_operasi, dan
 * penyambungan skor pemulihan (skor_aldrette/steward/bromage_pasca_anestesi)
 * ke operasinya.
 *
 * MELEKAT PADA OPERASI, BUKAN PADA KUNJUNGAN. Ketiga tabel Khanza
 * berkunci no_rawat saja, sehingga pasien yang dioperasi dua kali dalam
 * satu perawatan punya catatan anestesi yang tidak bisa dibedakan milik
 * operasi yang mana — dan pada pembedahan ulang karena perdarahan,
 * justru perbandingan antara keduanya yang paling dicari. Aturan yang
 * sama sudah ditegakkan pada checklist keselamatan bedah di item C.
 *
 * SKOR PEMULIHAN TIDAK DIBUATKAN TABEL BARU. skor_aldrette_pasca_anestesi
 * Khanza menyimpan TIGA representasi hal yang sama sekaligus:
 * penilaian_skala1 (labelnya, sebagai teks enum), penilaian_nilai1
 * (angkanya), dan penilaian_totalnilai (jumlahnya). Label bisa berbeda
 * dari angkanya, dan totalnya bisa berbeda dari kelima angka yang
 * melahirkannya — tiga tempat untuk satu kebenaran.
 *
 * Padahal Aldrete, Bromage, dan Steward SUDAH ADA di sini sebagai
 * template instrumen sejak item F, lengkap dengan aturan skornya, dan
 * mekanisme form_responses sejak item A sudah menghitung skor dari
 * jawaban serta membekukannya. Yang benar-benar kurang cuma satu:
 * penyambungnya ke operasi. Maka yang dibuat tabel penyambung, bukan
 * tabel skor kedua.
 *
 * TANDA VITAL TIDAK DIDUPLIKASI. catatan_anestesi_sedasi memasang
 * pre_induksi_td, _nadi, _rr, _suhu, _o2, dan penilaian_pre_anestesi
 * mengulangnya lagi. Sejak item D rumah sakit ini punya panel observasi
 * untuk itu, dan tanda vital pra-induksi yang disimpan dua kali adalah
 * dua tekanan darah yang bisa berbeda pada menit yang sama.
 *
 * KELAS ASA DITAMBAHKAN. Status fisik ASA menentukan risiko anestesi dan
 * dipakai di seluruh dunia; Khanza tidak menyediakan kolomnya di
 * penilaian_pre_anestesi, sehingga penilaian risiko yang paling ringkas
 * justru yang tidak tercatat.
 *
 * WAKTU ANESTESI DAN WAKTU BEDAH DICATAT TERPISAH, dan durasinya
 * dihitung. Keduanya memang tidak sama — anestesi mulai sebelum insisi
 * dan berakhir sesudah luka ditutup — dan selisihnya yang jadi dasar
 * penghitungan jasa anestesi maupun evaluasi efisiensi kamar operasi.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createRecords();
        $this->createRecoveryAssessments();
        $this->createPostoperativeOrders();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.postoperative_orders');
        Schema::dropIfExists(self::S.'.recovery_assessments');
        Schema::dropIfExists(self::S.'.anaesthesia_records');
    }

    private function createRecords(): void
    {
        Schema::create(self::S.'.anaesthesia_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            // NOT NULL, dan itu intinya — lihat catatan kelas.
            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->string('surgeon_name', 150)->nullable();
            $table->unsignedBigInteger('anaesthetist_practitioner_id')->nullable();
            $table->string('anaesthetist_name', 150)->nullable();

            $table->string('pre_op_diagnosis', 200)->nullable();
            $table->string('procedure_name', 200)->nullable();
            $table->string('post_op_diagnosis', 200)->nullable();

            $table->string('asa_class', 5)->nullable()
                ->comment('1-6, dengan akhiran E untuk operasi darurat — tidak ada di Khanza');
            $table->string('anaesthesia_type', 30)->nullable()
                ->comment('umum, spinal, epidural, blok-perifer, lokal, sedasi');
            $table->string('airway', 30)->nullable()->comment('tanpa-alat, sungkup, lma, ett, trakeostomi');
            $table->string('airway_note', 200)->nullable();

            // Empat waktu, masing-masing dicatat; TIDAK ADA kolom durasi.
            $table->timestampTz('anaesthesia_start_at')->nullable();
            $table->timestampTz('surgery_start_at')->nullable();
            $table->timestampTz('surgery_end_at')->nullable();
            $table->timestampTz('anaesthesia_end_at')->nullable();

            $table->text('premedication')->nullable();
            $table->text('induction_agents')->nullable();
            $table->text('maintenance_agents')->nullable();
            $table->text('muscle_relaxant')->nullable();
            $table->text('reversal_agents')->nullable();
            $table->text('analgesia')->nullable();
            $table->text('fluids')->nullable();
            $table->text('complications')->nullable()
                ->comment('Kosong berarti belum dicatat; "tidak ada penyulit" ditulis sebagai kalimat');

            // TIDAK ADA KOLOM TANDA VITAL — panel observasi sejak item D.

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('registration_id');
        });

        DB::statement('ALTER TABLE '.self::S.".anaesthesia_records
            ADD CONSTRAINT anaesthesia_records_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".anaesthesia_records
            ADD CONSTRAINT anaesthesia_records_asa_check
            CHECK (asa_class IS NULL
                   OR asa_class IN ('1','2','3','4','5','6','1E','2E','3E','4E','5E'))");

        // Urutan waktunya: anestesi mulai sebelum insisi, insisi sebelum
        // luka ditutup, dan anestesi berakhir paling akhir. Waktu yang
        // terbalik menghasilkan durasi negatif yang akan ditagihkan.
        DB::statement('ALTER TABLE '.self::S.'.anaesthesia_records
            ADD CONSTRAINT anaesthesia_records_time_order_check
            CHECK ((surgery_start_at IS NULL OR anaesthesia_start_at IS NULL
                    OR surgery_start_at >= anaesthesia_start_at)
                   AND (surgery_end_at IS NULL OR surgery_start_at IS NULL
                        OR surgery_end_at >= surgery_start_at)
                   AND (anaesthesia_end_at IS NULL OR surgery_end_at IS NULL
                        OR anaesthesia_end_at >= surgery_end_at))');

        DB::statement('ALTER TABLE '.self::S.".anaesthesia_records
            ADD CONSTRAINT anaesthesia_records_final_check
            CHECK (status <> 'final'
                   OR (finalized_at IS NOT NULL
                       AND anaesthetist_name IS NOT NULL
                       AND anaesthesia_type IS NOT NULL
                       AND anaesthesia_start_at IS NOT NULL
                       AND anaesthesia_end_at IS NOT NULL))");

        // Satu operasi satu catatan anestesi.
        DB::statement('CREATE UNIQUE INDEX anaesthesia_records_one_per_operation
            ON '.self::S.".anaesthesia_records (operation_id)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    /**
     * Penyambung skor pemulihan ke operasinya.
     *
     * Skornya sendiri hidup di form_responses — sudah dihitung dari
     * jawaban dan dibekukan bersama versi templatenya. Yang disimpan di
     * sini cuma tautan, urutan penilaian, dan keputusan yang diambil
     * atasnya. Lihat catatan kelas.
     */
    private function createRecoveryAssessments(): void
    {
        Schema::create(self::S.'.recovery_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('anaesthesia_record_id')->nullable();
            $table->unsignedBigInteger('form_response_id');

            $table->string('instrument_code', 40)
                ->comment('aldrete, bromage, steward — disalin dari template yang dipakai');
            $table->timestampTz('assessed_at');
            $table->unsignedSmallInteger('sequence')->comment('Penilaian ke berapa, 1, 2, 3 — pemulihan dinilai berulang');

            $table->string('decision', 30)->nullable()
                ->comment('lanjut-observasi, pindah-bangsal, pindah-icu, pulang');
            $table->string('decision_note', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();

            $table->index(['operation_id', 'assessed_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".recovery_assessments
            ADD CONSTRAINT recovery_assessments_decision_check
            CHECK (decision IS NULL
                   OR decision IN ('lanjut-observasi','pindah-bangsal','pindah-icu','pulang'))");

        // Satu jawaban formulir dipakai satu kali; kalau tidak, satu skor
        // yang sama bisa terhitung sebagai dua penilaian pemulihan.
        DB::statement('CREATE UNIQUE INDEX recovery_assessments_response_unique
            ON '.self::S.'.recovery_assessments (form_response_id)');

        DB::statement('CREATE UNIQUE INDEX recovery_assessments_sequence_unique
            ON '.self::S.'.recovery_assessments (operation_id, instrument_code, sequence)');
    }

    private function createPostoperativeOrders(): void
    {
        Schema::create(self::S.'.postoperative_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('ordered_at');
            $table->unsignedBigInteger('practitioner_id')->nullable();
            $table->string('practitioner_name', 150);

            $table->text('care_location')->nullable()->comment('rawat_paska_operasi: mau dirawat di mana');
            $table->text('fluids')->nullable();
            $table->text('antibiotics')->nullable();
            $table->text('analgesics')->nullable();
            $table->text('other_medication')->nullable();
            $table->text('diet')->nullable();
            $table->text('laboratory')->nullable();
            $table->text('transfusion')->nullable();
            $table->text('mobilisation')->nullable()
                ->comment('Tidak ada di Khanza; mobilisasi dini menentukan pemulihan dan pencegahan trombosis');
            $table->text('wound_care')->nullable()->comment('Tidak ada di Khanza');
            $table->text('other')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['operation_id', 'ordered_at']);
            $table->index(['patient_id', 'ordered_at']);
        });

        // Instruksi pasca-operasi yang seluruh bagiannya kosong bukan
        // instruksi — dan perawat bangsal yang menerimanya tidak tahu apa
        // yang harus dikerjakan.
        DB::statement('ALTER TABLE '.self::S.".postoperative_orders
            ADD CONSTRAINT postoperative_orders_not_empty_check
            CHECK (COALESCE(btrim(care_location), '') <> ''
                   OR COALESCE(btrim(fluids), '') <> ''
                   OR COALESCE(btrim(antibiotics), '') <> ''
                   OR COALESCE(btrim(analgesics), '') <> ''
                   OR COALESCE(btrim(other_medication), '') <> ''
                   OR COALESCE(btrim(diet), '') <> ''
                   OR COALESCE(btrim(laboratory), '') <> ''
                   OR COALESCE(btrim(transfusion), '') <> ''
                   OR COALESCE(btrim(mobilisation), '') <> ''
                   OR COALESCE(btrim(wound_care), '') <> ''
                   OR COALESCE(btrim(other), '') <> '')");
    }
};
