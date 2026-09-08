<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Informed consent yang bisa dibuktikan (domain P item A).
 *
 * DUDUK PERKARANYA — DAN KALI INI YANG KURANG ADALAH KITA, BUKAN KHANZA.
 *
 * `correspondence.patient_consents` yang sudah ada menyimpan persetujuan
 * sebagai SATU uraian bebas ditambah satu keputusan setuju/menolak. Itu
 * mencatat bahwa seseorang setuju. Yang TIDAK dicatatnya adalah apakah
 * orang itu diberi tahu apa yang ia setujui — dan justru itulah seluruh
 * isi persetujuan tindakan kedokteran.
 *
 * Khanza menyimpannya dengan benar: `persetujuan_penolakan_tindakan`
 * punya SEBELAS butir informasi (diagnosis, tindakan, indikasi, tata
 * cara, tujuan, risiko, komplikasi, prognosis, alternatif berikut
 * risikonya, biaya, lain-lain) dan MASING-MASING punya penanda
 * konfirmasi sendiri. Permenkes 290/2008 pasal 7 ayat (3) memang
 * menuntut penjelasan yang mencakup butir-butir itu, dan pasal 45 UU
 * Praktik Kedokteran menempatkan penjelasan sebagai SYARAT sahnya
 * persetujuan, bukan pelengkapnya.
 *
 * Bedanya nyata saat ada sengketa. Formulir yang cuma berbunyi "setuju
 * atas tindakan X" tidak bisa menjawab pertanyaan "apakah risikonya
 * dijelaskan?" — dan rumah sakit yang tidak bisa menjawab itu dianggap
 * tidak menjelaskan. Formulir yang mencatat per butir bisa menjawabnya,
 * termasuk ketika jawabannya memberatkan rumah sakit sendiri.
 *
 * BUTIRNYA BARIS, BUKAN KOLOM. Aturan yang dipakai sepanjang proyek ini:
 * kolom tetap hanya untuk himpunan tertutup yang batasnya ditetapkan di
 * luar rumah sakit — empat kala persalinan, karena kala kelima tidak
 * mungkin ada. Sebelas butir ini BUKAN himpunan seperti itu: Permenkes
 * menetapkan ISI MINIMAL, dan rumah sakit yang menambahkan "kemungkinan
 * perluasan tindakan" pada formulir bedahnya punya butir kedua belas
 * yang sah. Kalau butir jadi kolom, butir kedua belas menuntut migrasi;
 * lebih buruk lagi, seluruh persetujuan lama mendadak punya kolom kosong
 * yang terbaca "tidak dijelaskan" padahal butirnya belum ada waktu itu.
 *
 * BELUM DITANYAKAN ADALAH NULL, BUKAN FALSE. `confirmed` boleh kosong,
 * dan itu keadaan ketiga yang berbeda dari dua lainnya: null berarti
 * butir ini belum sempat dijelaskan, false berarti pasien menyatakan
 * belum paham. Menyamakan keduanya jadi "belum dikonfirmasi" menghapus
 * satu-satunya jejak bahwa ada pasien yang bilang tidak paham lalu tetap
 * diminta menandatangani.
 *
 * ATURAN YANG TIDAK SIMETRIS, DAN MEMANG SENGAJA. Persetujuan tidak
 * boleh direkam selama masih ada butir yang null — orang tidak bisa
 * menyetujui apa yang belum pernah disampaikan kepadanya. Tapi PENOLAKAN
 * boleh: pasien berhak menghentikan penjelasan di tengah jalan dan
 * menolak saat itu juga, dan memaksa petugas mengisi seluruh butir dulu
 * hanya akan melahirkan konfirmasi karangan demi menyimpan formulir.
 * Penolakan yang tercatat "menolak sebelum risiko sempat dijelaskan"
 * adalah catatan yang jujur; penolakan yang tercatat "seluruh butir
 * dijelaskan dan dipahami" padahal tidak, adalah catatan palsu.
 *
 * SIAPA YANG MENANDATANGANI ADALAH BAGIAN DARI KEABSAHANNYA. Kolom
 * `witness_name` sendirian tidak cukup. Permenkes 290/2008 pasal 13-14
 * hanya membolehkan keluarga terdekat memberi persetujuan bila pasien
 * tidak kompeten — anak di bawah umur, tidak sadar, atau terganggu
 * kesadarannya. Karena itu penanda tangan dicatat lengkap (nama,
 * hubungan, identitas, kontak) dan ALASAN PERWAKILAN wajib diisi bila
 * hubungannya bukan diri sendiri. Tanpa alasan itu, tidak ada yang bisa
 * menilai belakangan apakah perwakilannya sah — dan persetujuan dari
 * orang yang tidak berhak sama saja dengan tidak ada persetujuan.
 *
 * TEMPLATE DISALIN, TIDAK DIRUJUK. Sama seperti template formulir
 * asesmen pada domain M: teks butir dibekukan ke dalam persetujuan saat
 * ditandatangani. Kalau dirujuk, revisi kalimat risiko tahun depan akan
 * mengubah bunyi persetujuan yang ditandatangani tahun ini — dokumen
 * hukum yang berubah sendiri tanpa ada yang menyentuhnya.
 *
 * MENGAPA TIDAK MEMAKAI `catalog.form_templates` YANG SUDAH ADA. Sempat
 * ditimbang, lalu ditolak dengan alasan yang bukan selera. Pertama,
 * bentuknya lain: form_templates adalah PERTANYAAN yang diajukan kepada
 * pasien lalu diskor; ini adalah PERNYATAAN yang dijelaskan kepada
 * pasien lalu dikonfirmasi, dan hasilnya keputusan hukum, bukan temuan
 * klinis. Kedua, dan ini yang menentukan: jawabannya akan mendarat di
 * `clinical.form_responses`, sementara keputusan, identitas penanda
 * tangan, dan saksi tetap di `correspondence` — bukti penjelasan dan
 * keputusan yang dibuktikannya akan tinggal di dua konteks berbeda,
 * dan aturan batas konteks proyek ini juga melarang correspondence
 * menulis ke schema clinical. Bukti yang bisa terpisah dari dokumennya
 * bukan bukti.
 *
 * DAFTAR ALASAN PENOLAKAN SENGAJA DIBIARKAN KOSONG. Tabelnya dibuat,
 * isinya tidak. Kosakata alasan menolak anjuran medis adalah diskresi
 * RSP UI — Khanza pun cuma menyediakan kode 3 huruf tanpa daftar baku.
 * Garis yang dipakai sepanjang proyek ini berlaku di sini juga: daftar
 * yang ditetapkan di luar rumah sakit boleh disalin, diskresi rumah
 * sakit tidak boleh ditebak. Tabel kosong itu jujur; tabel berisi lima
 * alasan karangan melahirkan statistik resmi tentang kategori yang tidak
 * pernah disepakati siapa pun.
 */
return new class extends Migration
{
    private const S = 'correspondence';

    /**
     * Hubungan penanda tangan dengan pasien. Daftar ini BOLEH ditetapkan
     * karena bukan diskresi rumah sakit: Permenkes 290/2008 pasal 1
     * angka 5 mendefinisikan "keluarga terdekat" secara terbatas —
     * suami/istri, ayah/ibu kandung, anak kandung, saudara kandung, atau
     * pengampu. 'lainnya' disediakan bukan sebagai pintu belakang, tapi
     * karena keadaan darurat memang mengenal penanda tangan di luar
     * daftar itu, dan menyembunyikannya lebih buruk daripada mencatatnya.
     */
    private const HUBUNGAN = [
        'diri-sendiri', 'suami', 'istri', 'ayah', 'ibu',
        'anak', 'saudara-kandung', 'pengampu', 'lainnya',
    ];

    public function up(): void
    {
        // ---------------------------------------------------------- template

        Schema::create(self::S.'.consent_templates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 60)->comment('mis. persetujuan-bedah-sesar');
            $table->unsignedSmallInteger('version')->default(1);

            $table->string('name', 150);
            $table->string('consent_type', 30)->comment('Jenis persetujuan yang dilayani template ini');

            // Perkiraan biaya ikut di template karena termasuk butir yang
            // wajib dijelaskan, tapi nilainya boleh kosong: banyak tindakan
            // baru bisa ditaksir setelah pasien diperiksa, dan angka nol
            // akan terbaca sebagai "gratis".
            $table->decimal('estimated_cost', 14, 2)->nullable();

            $table->text('note')->nullable()->comment('Rujukan pedoman/SPO yang mendasarinya');

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['code', 'version']);
            $table->index(['consent_type', 'is_active']);
        });

        // Satu versi aktif per kode — dua versi aktif berarti dua pasien
        // menandatangani penjelasan berbeda untuk tindakan yang sama.
        DB::statement('CREATE UNIQUE INDEX consent_template_aktif_unique
            ON '.self::S.'.consent_templates (code) WHERE is_active');

        Schema::create(self::S.'.consent_template_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('template_id')->constrained(self::S.'.consent_templates')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('label', 100)->comment('Nama butir, mis. Risiko');
            $table->text('body')->comment('Kalimat yang dibacakan kepada pasien');

            // Butir wajib tidak boleh dilewati saat menyetujui. Butir tidak
            // wajib (mis. "lain-lain") boleh kosong tanpa memblokir.
            $table->boolean('is_required')->default(true);

            $table->timestampsTz();

            $table->unique(['template_id', 'position']);
        });

        // ------------------------------------------------- alasan penolakan

        Schema::create(self::S.'.medical_advice_refusal_reasons', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // ------------------------------------------- butir pada persetujuan

        Schema::create(self::S.'.consent_information_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('consent_id')->constrained(self::S.'.patient_consents')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('label', 100);

            // SALINAN, bukan rujukan ke template — lihat catatan di atas.
            $table->text('body');
            $table->boolean('is_required')->default(true);

            /*
             * TIGA KEADAAN, bukan dua:
             *   null  — butir ini belum sempat dijelaskan
             *   false — sudah dijelaskan, pasien menyatakan belum paham
             *   true  — sudah dijelaskan dan dinyatakan paham
             */
            $table->boolean('confirmed')->nullable();
            $table->text('confirmation_note')->nullable()
                ->comment('Wajib bila confirmed=false: apa yang belum dipahami');

            $table->timestampsTz();

            $table->unique(['consent_id', 'position']);
        });

        // -------------------------------------- perluasan patient_consents

        Schema::table(self::S.'.patient_consents', function (Blueprint $table) {
            // Jejak template yang dipakai. Nomor versi ikut dibekukan supaya
            // pertanyaan "versi mana yang ditandatangani" punya jawaban
            // meski templatenya sudah direvisi berkali-kali sesudahnya.
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('template_code', 60)->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->string('template_name', 150)->nullable();

            // Penanda tangan — identitas dan kewenangannya.
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_relationship', 20)->nullable();
            $table->string('signer_id_number', 30)->nullable()->comment('NIK/KTP penanda tangan');
            $table->date('signer_birth_date')->nullable();
            $table->string('signer_sex', 10)->nullable();
            $table->string('signer_address', 200)->nullable();
            $table->string('signer_phone', 30)->nullable();
            $table->text('delegation_reason')->nullable()
                ->comment('Wajib bila hubungan bukan diri-sendiri: mengapa pasien tidak menandatangani sendiri');

            // Dokter yang memberi penjelasan. Berbeda dari issued_by, yang
            // adalah petugas yang mengetikkan formulir — dan pada sengketa
            // yang ditanya adalah yang menjelaskan, bukan yang mengetik.
            $table->unsignedBigInteger('explained_by')->nullable()->comment('ID praktisi organization, referensi longgar');
            $table->string('explained_by_name', 150)->nullable();

            // Khusus penolakan anjuran medis.
            $table->unsignedBigInteger('refusal_reason_id')->nullable();
            $table->text('refusal_risk_explained')->nullable()
                ->comment('Akibat yang dijelaskan bila anjuran ditolak');

            $table->index('template_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.patient_consents
            ADD CONSTRAINT patient_consents_signer_relationship_check
            CHECK (signer_relationship IS NULL OR signer_relationship IN (\''.implode("','", self::HUBUNGAN).'\'))');

        DB::statement('ALTER TABLE '.self::S.".patient_consents
            ADD CONSTRAINT patient_consents_signer_sex_check
            CHECK (signer_sex IS NULL OR signer_sex IN ('L','P'))");

        /*
         * Perwakilan tanpa alasan ditolak di tingkat basis data, bukan cuma
         * di service — karena service bukan satu-satunya pintu ke tabel ini,
         * dan persetujuan yang masuk lewat pintu lain tetap harus bisa
         * menjelaskan kewenangan penanda tangannya.
         */
        DB::statement('ALTER TABLE '.self::S.".patient_consents
            ADD CONSTRAINT patient_consents_delegation_check
            CHECK (
                signer_relationship IS NULL
                OR (signer_relationship = 'diri-sendiri' AND delegation_reason IS NULL)
                OR (signer_relationship <> 'diri-sendiri' AND delegation_reason IS NOT NULL)
            )");

        // Keadaan ketiga pada keputusan: belum dikonfirmasi. Kolomnya
        // varchar(10) sejak awal — 'belum-dikonfirmasi' tidak muat.
        DB::statement('ALTER TABLE '.self::S.'.patient_consents ALTER COLUMN decision TYPE varchar(20)');
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_decision_check');
        DB::statement('ALTER TABLE '.self::S.".patient_consents ADD CONSTRAINT patient_consents_decision_check
            CHECK (decision IN ('setuju','menolak','belum-dikonfirmasi'))");

        DB::statement('ALTER TABLE '.self::S.'.patient_consents
            ADD CONSTRAINT patient_consents_refusal_reason_fk
            FOREIGN KEY (refusal_reason_id) REFERENCES '.self::S.'.medical_advice_refusal_reasons (id)');

        DB::statement('COMMENT ON COLUMN '.self::S.".patient_consents.procedure_description IS
            'Ringkasan tindakan. Rincian penjelasan ada di consent_information_items — kolom ini bukan lagi satu-satunya bukti penjelasan.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_refusal_reason_fk');
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_delegation_check');
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_signer_sex_check');
        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_signer_relationship_check');

        DB::statement('ALTER TABLE '.self::S.'.patient_consents DROP CONSTRAINT patient_consents_decision_check');
        DB::statement('ALTER TABLE '.self::S.".patient_consents ADD CONSTRAINT patient_consents_decision_check
            CHECK (decision IN ('setuju','menolak'))");

        Schema::table(self::S.'.patient_consents', function (Blueprint $table) {
            $table->dropColumn([
                'template_id', 'template_code', 'template_version', 'template_name',
                'signer_name', 'signer_relationship', 'signer_id_number', 'signer_birth_date',
                'signer_sex', 'signer_address', 'signer_phone', 'delegation_reason',
                'explained_by', 'explained_by_name',
                'refusal_reason_id', 'refusal_risk_explained',
            ]);
        });

        Schema::dropIfExists(self::S.'.consent_information_items');
        Schema::dropIfExists(self::S.'.medical_advice_refusal_reasons');
        Schema::dropIfExists(self::S.'.consent_template_items');
        Schema::dropIfExists(self::S.'.consent_templates');
    }
};
