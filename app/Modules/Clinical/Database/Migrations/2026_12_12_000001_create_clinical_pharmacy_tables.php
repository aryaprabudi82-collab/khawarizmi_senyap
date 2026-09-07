<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Farmasi klinis (domain M item I).
 *
 * Menaungi rekonsiliasi_obat, rekonsiliasi_obat_detail_obat,
 * rekonsiliasi_obat_konfirmasi, konseling_farmasi,
 * pelayanan_informasi_obat, dan jawaban_pio_apoteker.
 *
 * RUMAHNYA CLINICAL, BUKAN PHARMACY, dan itu diperiksa bukan ditebak:
 * permissions.json menandai keenamnya context "clinical", domain M
 * "Rekam Medis". Yang dikerjakan apoteker di sini memang pekerjaan
 * farmasi, tapi yang dihasilkan adalah catatan tentang pasien — sama
 * seperti tindakan_ralan yang juga tinggal di clinical meski dikerjakan
 * perawat.
 *
 * ALERGI TIDAK DIKETIK ULANG DI SINI. rekonsiliasi_obat Khanza punya
 * alergi_obat varchar(70), manifestasi_alergi varchar(70), dan
 * dampak_alergi enum — tiga kolom yang persis sudah dimiliki
 * clinical.allergies (substance, reaction, severity) sejak awal, dan
 * yang di sana melekat pada PASIEN, bukan pada satu wawancara.
 * Menyalinnya ke sini melahirkan dua daftar alergi yang bisa berbeda,
 * dan yang dibaca saat menelaah resep adalah daftar yang satunya lagi.
 *
 * Maka rekonsiliasi justru MENULIS KE daftar alergi pasien: wawancara
 * obat memang saat alergi dari luar rumah sakit ditemukan. Yang
 * dibekukan di sini hanya SALINAN keadaan alergi saat wawancara,
 * supaya dokumennya tetap menggambarkan apa yang diketahui waktu itu —
 * pola yang sama dengan resume medis.
 *
 * TINDAK LANJUT PUNYA TIGA KEMUNGKINAN, BUKAN DUA. Khanza memakai
 * enum('Lanjut','Stop') lalu menaruh perubahannya di kolom teks
 * perubahan_aturan_pakai — artinya obat yang diteruskan dengan dosis
 * berbeda tercatat sebagai "Lanjut" begitu saja, dan perubahannya
 * hanya terbaca kalau ada yang membuka kolom teksnya. Di sini
 * "ubah-aturan" jadi pilihan tersendiri, dan aturan barunya WAJIB
 * diisi — ditegakkan CHECK, karena obat yang diubah aturannya tanpa
 * menyebut aturan barunya adalah instruksi yang tidak bisa dijalankan.
 *
 * NAMA OBAT BOLEH TEKS BEBAS, DAN ITU DISENGAJA. Yang direkonsiliasi
 * adalah obat yang dibawa pasien DARI LUAR: obat warung, obat dari
 * fasilitas lain, obat yang tidak ada di formularium kita. Mewajibkan
 * drug_id akan membuat obat yang paling perlu dicatat justru yang
 * tidak bisa dicatat. Tautan ke master obat disediakan, tidak
 * diwajibkan.
 *
 * PIO TIDAK SELALU TENTANG SATU PASIEN. pelayanan_informasi_obat
 * Khanza memasang no_rawat NOT NULL, padahal penanyanya boleh petugas
 * kesehatan yang bertanya soal stabilitas atau interaksi obat tanpa
 * ada pasien tertentu di hadapannya. Di sini registration_id boleh
 * kosong; yang wajib adalah siapa yang bertanya.
 *
 * LAMA JAWABAN DIHITUNG, TIDAK DISIMPAN. jawaban_pio_apoteker
 * menyimpan penyampaian_jawaban enum('Segera','Dalam 24 Jam','Lebih
 * Dari 24 Jam') padahal waktu bertanya dan waktu menjawab dua-duanya
 * sudah tercatat. Kategori yang disimpan bisa berbeda dari kedua
 * timestamp yang melahirkannya, dan yang salah selalu ketahuan
 * belakangan. Aturan proyek yang sama dengan balans cairan dan lama
 * pelayanan: nilai turunan tidak disimpan.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createSequences();
        $this->createReconciliations();
        $this->createCounsellings();
        $this->createInformationRequests();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.number_sequences');
        Schema::dropIfExists(self::S.'.drug_information_requests');
        Schema::dropIfExists(self::S.'.pharmacy_counsellings');
        Schema::dropIfExists(self::S.'.medication_reconciliation_items');
        Schema::dropIfExists(self::S.'.medication_reconciliations');
    }

    /**
     * Penomoran dokumen milik konteks ini sendiri.
     *
     * Pola yang sama dengan pharmacy.number_sequences, TIDAK memakai
     * tabelnya: nomor permintaan informasi obat bukan urusan farmasi
     * melainkan urusan konteks ini, dan menumpang tabel konteks lain
     * membuat dua modul berebut baris yang sama.
     */
    private function createSequences(): void
    {
        Schema::create(self::S.'.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    private function createReconciliations(): void
    {
        Schema::create(self::S.'.medication_reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->string('occasion', 30)
                ->comment('admisi, transfer-antar-ruang, pindah-faskes-lain, pulang');
            $table->timestampTz('interviewed_at');
            $table->string('informant_name', 150)->nullable()
                ->comment('Yang diwawancarai — pasien sendiri atau keluarganya');
            $table->string('informant_relation', 60)->nullable();

            $table->unsignedBigInteger('pharmacist_id')->nullable();
            $table->string('pharmacist_name', 150)->nullable();

            // Salinan keadaan alergi SAAT wawancara. Daftar yang hidup tetap
            // clinical.allergies — lihat catatan kelas.
            $table->jsonb('allergies_at_interview')->default(DB::raw("'[]'::jsonb"));

            // rekonsiliasi_obat_konfirmasi: satu baris per rekonsiliasi, jadi
            // kolom, bukan tabel tersendiri.
            $table->timestampTz('received_by_pharmacy_at')->nullable();
            $table->timestampTz('confirmed_by_pharmacist_at')->nullable();
            $table->timestampTz('handed_to_patient_at')->nullable();

            $table->text('note')->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'interviewed_at']);
            $table->index(['status', 'interviewed_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".medication_reconciliations
            ADD CONSTRAINT medication_reconciliations_occasion_check
            CHECK (occasion IN ('admisi','transfer-antar-ruang','pindah-faskes-lain','pulang'))");

        DB::statement('ALTER TABLE '.self::S.".medication_reconciliations
            ADD CONSTRAINT medication_reconciliations_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        // Satu kunjungan boleh direkonsiliasi beberapa kali — memang begitu
        // aturannya: saat masuk, saat pindah ruang, dan saat pulang adalah
        // tiga wawancara berbeda tentang obat yang berbeda pula. Yang tidak
        // boleh berulang hanya kesempatan yang sama.
        DB::statement('CREATE UNIQUE INDEX medication_reconciliations_one_per_occasion
            ON '.self::S.".medication_reconciliations (registration_id, occasion)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");

        Schema::create(self::S.'.medication_reconciliation_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('reconciliation_id');

            // Teks bebas disengaja — lihat catatan kelas.
            $table->string('drug_name', 200);
            $table->unsignedBigInteger('drug_id')->nullable()
                ->comment('Terisi bila obatnya kebetulan ada di formularium kita; tidak diwajibkan');
            $table->string('kfa_code', 30)->nullable();

            $table->string('dose', 40)->nullable();
            $table->string('frequency', 40)->nullable();
            $table->string('route', 60)->nullable();
            $table->timestampTz('last_taken_at')->nullable();
            $table->string('source', 100)->nullable()->comment('Dari mana obatnya: faskes lain, beli sendiri, sisa resep lama');

            $table->string('decision', 20)->nullable()->comment('lanjut, stop, ubah-aturan');
            $table->string('new_instruction', 200)->nullable();
            $table->string('decision_reason', 200)->nullable();

            $table->timestampsTz();

            $table->index('reconciliation_id');
        });

        DB::statement('ALTER TABLE '.self::S.".medication_reconciliation_items
            ADD CONSTRAINT medication_reconciliation_items_decision_check
            CHECK (decision IS NULL OR decision IN ('lanjut','stop','ubah-aturan'))");

        // Obat yang diubah aturannya tanpa menyebut aturan barunya adalah
        // instruksi yang tidak bisa dijalankan siapa pun.
        DB::statement('ALTER TABLE '.self::S.".medication_reconciliation_items
            ADD CONSTRAINT medication_reconciliation_items_instruction_check
            CHECK (decision <> 'ubah-aturan'
                   OR (new_instruction IS NOT NULL AND btrim(new_instruction) <> ''))");
    }

    private function createCounsellings(): void
    {
        Schema::create(self::S.'.pharmacy_counsellings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('counselled_at');
            $table->string('diagnosis', 200)->nullable();
            $table->text('complaint')->nullable();
            $table->boolean('is_repeat_visit')->nullable()
                ->comment('pernah_datang — boleh kosong, "belum ditanyakan" bukan "tidak"');

            // Khanza menaruh seluruh daftar obat dalam satu kolom
            // obat_pemakaian varchar(700) dan riwayat alergi dalam 30
            // karakter. Keduanya di sini SALINAN dari yang sudah tercatat.
            $table->jsonb('medications')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('allergies')->default(DB::raw("'[]'::jsonb"));

            $table->text('counselling_given')->nullable();
            $table->text('follow_up')->nullable();

            $table->unsignedBigInteger('pharmacist_id')->nullable();
            $table->string('pharmacist_name', 150)->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'counselled_at']);
            $table->index(['status', 'counselled_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".pharmacy_counsellings
            ADD CONSTRAINT pharmacy_counsellings_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        // Konseling yang difinalkan harus menyebut apa yang disampaikan dan
        // siapa yang menyampaikannya: konseling adalah percakapan, dan
        // catatan konseling tanpa isi percakapan tidak membuktikan apa pun.
        DB::statement('ALTER TABLE '.self::S.".pharmacy_counsellings
            ADD CONSTRAINT pharmacy_counsellings_final_check
            CHECK (status <> 'final'
                   OR (finalized_at IS NOT NULL
                       AND pharmacist_name IS NOT NULL
                       AND counselling_given IS NOT NULL
                       AND btrim(counselling_given) <> ''))");
    }

    private function createInformationRequests(): void
    {
        Schema::create(self::S.'.drug_information_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('request_number', 30)->unique();

            // BOLEH KOSONG — lihat catatan kelas.
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('patient_name', 150)->nullable();

            $table->timestampTz('asked_at');
            $table->string('method', 20)->comment('lisan, tertulis, telepon');
            $table->string('asker_name', 150);
            $table->string('asker_kind', 30)->comment('pasien, keluarga-pasien, petugas-kesehatan');
            $table->string('asker_phone', 30)->nullable();

            $table->string('question_kind', 40);
            $table->string('question_kind_note', 100)->nullable()
                ->comment('Wajib saat jenisnya lain-lain, kalau tidak "Lain-lain" jadi keranjang tanpa isi');
            $table->text('question');

            $table->timestampTz('answered_at')->nullable();
            $table->string('answer_method', 20)->nullable();
            $table->text('answer')->nullable();
            $table->text('reference')->nullable()
                ->comment('Sumber pustaka jawaban — jawaban informasi obat tanpa rujukan adalah pendapat');
            $table->unsignedBigInteger('answered_by')->nullable();
            $table->string('answered_by_name', 150)->nullable();

            $table->string('status', 20)->default('terbuka');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'asked_at']);
            $table->index(['question_kind', 'asked_at']);
            $table->index('patient_id');
        });

        DB::statement('ALTER TABLE '.self::S.".drug_information_requests
            ADD CONSTRAINT drug_information_requests_method_check
            CHECK (method IN ('lisan','tertulis','telepon')
                   AND (answer_method IS NULL OR answer_method IN ('lisan','tertulis','telepon')))");

        DB::statement('ALTER TABLE '.self::S.".drug_information_requests
            ADD CONSTRAINT drug_information_requests_asker_check
            CHECK (asker_kind IN ('pasien','keluarga-pasien','petugas-kesehatan'))");

        DB::statement('ALTER TABLE '.self::S.".drug_information_requests
            ADD CONSTRAINT drug_information_requests_status_check
            CHECK (status IN ('terbuka','dijawab','dibatalkan'))");

        // Pertanyaan yang berstatus dijawab harus benar-benar punya jawaban,
        // waktunya, dan penjawabnya. Tanpa ini "dijawab" hanya penanda yang
        // bisa dipasang tanpa ada yang menjawab apa pun.
        DB::statement('ALTER TABLE '.self::S.".drug_information_requests
            ADD CONSTRAINT drug_information_requests_answered_check
            CHECK (status <> 'dijawab'
                   OR (answered_at IS NOT NULL
                       AND answer IS NOT NULL AND btrim(answer) <> ''
                       AND answered_by_name IS NOT NULL))");

        // Jawaban tidak boleh tercatat mendahului pertanyaannya.
        DB::statement('ALTER TABLE '.self::S.'.drug_information_requests
            ADD CONSTRAINT drug_information_requests_order_check
            CHECK (answered_at IS NULL OR answered_at >= asked_at)');
    }
};
