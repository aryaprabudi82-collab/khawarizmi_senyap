<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filantropi / ZIS: kelayakan & penyaluran dana kesehatan (domain T) —
 * konteks baru `philanthropy`.
 *
 * Enam belas kode, dan SEMUANYA kosakata satu instrumen: asesmen
 * kelayakan calon penerima dana kesehatan. Pengeluaran, penghasilan,
 * ukuran rumah, dinding, lantai, atap, kepemilikan, kamar mandi, dapur,
 * kursi, PHBS, elektronik, ternak, jenis simpanan, asnaf, dan kondisi
 * patologis.
 *
 * ENAM BELAS DAFTAR BERBENTUK SAMA JADI SATU TABEL BERKATEGORI. Kelima
 * belas tabel `zis_keterangan_*` di Khanza identik kolom per kolom: kode
 * tiga huruf dan satu keterangan. Membuat enam belas tabel untuk satu
 * bentuk berarti enam belas layar, enam belas kueri, dan enam belas
 * tempat memperbaiki satu kesalahan yang sama. Aturan yang sama sudah
 * dipakai untuk empat daftar periksa risiko ICRA pada domain R.
 *
 * SATU KODE TIDAK PUNYA TABEL DI KHANZA, DAN ITU BUKAN KELALAIAN KAMI.
 * `zis_kepemilikan_rumah_penerima_dankes` terdaftar di katalog, tapi
 * `sik_schema.sql` tidak punya tabelnya — dan kelas Java yang ditunjuk
 * menunya adalah kelas ATAP RUMAH, salinan yang lupa diganti. Jadi pada
 * Khanza, kepemilikan rumah tidak pernah bisa disimpan. Di sini ia satu
 * kategori seperti lima belas lainnya.
 *
 * ASNAF BOLEH DISEED, SISANYA TIDAK. Delapan golongan asnaf ditetapkan
 * Al-Qur'an surah At-Taubah ayat 60 dan tidak berubah; menyalinnya bukan
 * mengarang. Sebaliknya, batas penghasilan, ukuran rumah yang dianggap
 * layak, dan jenis dinding yang dianggap tidak layak adalah penilaian
 * amil RSP UI — menebaknya berarti menerbitkan kriteria kemiskinan resmi
 * yang tidak pernah disepakati siapa pun, lalu memakainya menolak orang.
 *
 * TIDAK ADA PUTUSAN OTOMATIS. Tiap pilihan boleh diberi bobot, dan kalau
 * bobotnya diisi, totalnya dihitung. Tapi total TIDAK pernah berubah
 * sendiri jadi "layak" atau "tidak layak": putusannya keputusan manusia,
 * berikut nama pemutus dan alasannya. Ambang yang ditebak sistem akan
 * menolak keluarga sungguhan dengan angka yang tidak pernah ditetapkan
 * siapa pun, dan yang ditolak tidak punya siapa-siapa untuk ditanyai.
 *
 * PENYALURAN WAJIB MENYEBUT PENERIMANYA. `ambil_dankes` Khanza cuma
 * berisi tanggal, kategori, dan jumlah — TANPA rujukan ke penerima sama
 * sekali. Bantuan yang tercatat tanpa penerima tidak bisa diaudit, tidak
 * bisa mendeteksi penerimaan ganda, dan tidak bisa menjawab pertanyaan
 * yang paling wajar: apakah keluarga ini pernah dibantu. Di sini
 * penyaluran menunjuk penerima DAN asesmen yang mendasarinya.
 *
 * ZAKAT HANYA UNTUK ASNAF. Zakat yang disalurkan kepada orang di luar
 * delapan golongan tidak sah sebagai zakat — dan itu bukan pilihan
 * kebijakan rumah sakit, melainkan syarat yang datang dari luar. Karena
 * itu penyaluran bersumber zakat menuntut golongan asnaf penerimanya
 * sudah dicatat. Infak, sedekah, dan CSR tidak terikat syarat itu.
 *
 * YANG SENGAJA TIDAK DIBANGUN: pembukuan dananya. Penerimaan dan saldo
 * dana ZIS adalah transaksi keuangan, dan konteks `finance` sudah
 * memegangnya; menulis jurnal dari sini juga dilarang aturan batas
 * konteks. Yang dicatat di sini adalah KEPADA SIAPA dan ATAS DASAR APA —
 * pertanyaan yang justru tidak bisa dijawab pembukuan.
 */
return new class extends Migration
{
    private const S = 'philanthropy';

    /**
     * Enam belas kategori kriteria — satu per kode Khanza.
     */
    private const KATEGORI = [
        'pengeluaran', 'penghasilan', 'ukuran-rumah', 'dinding-rumah',
        'lantai-rumah', 'atap-rumah', 'kepemilikan-rumah', 'kamar-mandi',
        'dapur', 'kursi', 'phbs', 'elektronik', 'ternak', 'simpanan',
        'asnaf', 'patologis',
    ];

    /**
     * Delapan golongan asnaf, At-Taubah 60. Boleh diseed: batasnya
     * ditetapkan di luar rumah sakit dan tidak berubah.
     */
    private const ASNAF = [
        ['FAK', 'Fakir', 'Tidak punya harta maupun penghasilan untuk memenuhi kebutuhan pokok.'],
        ['MIS', 'Miskin', 'Punya penghasilan tapi tidak mencukupi kebutuhan pokok.'],
        ['AML', 'Amil', 'Pengelola zakat yang bertugas menghimpun dan menyalurkan.'],
        ['MUA', 'Muallaf', 'Orang yang baru memeluk Islam atau dilunakkan hatinya.'],
        ['RIQ', 'Riqab', 'Memerdekakan dari perbudakan atau belenggu yang setara.'],
        ['GHA', 'Gharim', 'Berutang untuk kebutuhan yang halal dan tidak mampu melunasinya.'],
        ['FIS', 'Fisabilillah', 'Berjuang di jalan Allah.'],
        ['IBN', 'Ibnu Sabil', 'Musafir yang kehabisan bekal dalam perjalanan.'],
    ];

    private const SUMBER_DANA = ['zakat', 'infak', 'sedekah', 'csr', 'lainnya'];

    private const PUTUSAN = ['belum-diputuskan', 'layak', 'tidak-layak'];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::S);

        Schema::create(self::S.'.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        // ------------------------------------------------- kriteria

        Schema::create(self::S.'.assessment_criteria', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('category', 30);
            $table->string('code', 20)->unique();
            $table->string('name', 120);

            /*
             * Bobot BOLEH kosong, dan itu keadaan bawaannya. Kalau diisi,
             * totalnya dihitung — tapi total tidak pernah berubah sendiri
             * jadi putusan. Ambang yang ditebak sistem akan menolak keluarga
             * sungguhan dengan angka yang tidak pernah ditetapkan siapa pun.
             */
            $table->smallInteger('weight')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.".assessment_criteria ADD CONSTRAINT assessment_criteria_category_check
            CHECK (category IN ('".implode("','", self::KATEGORI)."'))");

        // ------------------------------------------------- penerima

        Schema::create(self::S.'.recipients', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('recipient_number', 24)->unique();
            $table->string('name', 150);
            $table->string('id_number', 30)->nullable()->comment('NIK; boleh kosong untuk yang belum punya');
            $table->string('sex', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address', 250)->nullable();
            $table->string('phone', 30)->nullable();

            /*
             * Rujukan longgar ke pasien, bukan foreign key: penerima dana
             * kesehatan tidak selalu sedang jadi pasien, dan mengikatnya akan
             * menutup pintu bagi keluarga yang datang meminta bantuan
             * SEBELUM berobat — justru keadaan yang paling sering terjadi.
             */
            $table->unsignedBigInteger('patient_id')->nullable()->comment('ID pasien identity, referensi longgar');
            $table->string('patient_mrn', 30)->nullable();

            $table->unsignedBigInteger('asnaf_criteria_id')->nullable()
                ->comment('Golongan asnaf; wajib ada sebelum menerima zakat');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('name');
        });

        DB::statement('ALTER TABLE '.self::S.'.recipients
            ADD CONSTRAINT recipients_asnaf_fk
            FOREIGN KEY (asnaf_criteria_id) REFERENCES '.self::S.'.assessment_criteria (id)');

        DB::statement('ALTER TABLE '.self::S.".recipients ADD CONSTRAINT recipients_sex_check
            CHECK (sex IS NULL OR sex IN ('L','P'))");

        // -------------------------------------------------- asesmen

        Schema::create(self::S.'.assessments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('assessment_number', 24)->unique();
            $table->unsignedBigInteger('recipient_id');
            $table->date('assessed_on');

            $table->string('surveyor_name', 150);
            $table->text('note')->nullable();

            $table->string('decision', 20)->default('belum-diputuskan');
            $table->text('decision_reason')->nullable();
            $table->string('decided_by_name', 150)->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->decimal('recommended_amount', 16, 2)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['recipient_id', 'decision']);
        });

        DB::statement('ALTER TABLE '.self::S.'.assessments
            ADD CONSTRAINT assessments_recipient_fk
            FOREIGN KEY (recipient_id) REFERENCES '.self::S.'.recipients (id)');

        DB::statement('ALTER TABLE '.self::S.".assessments ADD CONSTRAINT assessments_decision_check
            CHECK (decision IN ('".implode("','", self::PUTUSAN)."'))");

        /*
         * Putusan apa pun wajib menyebut pemutus dan alasannya — termasuk
         * yang MELULUSKAN. Berbeda dari ketaksimetrisan di domain lain, di
         * sini kedua arah sama-sama menentukan nasib orang dan sama-sama
         * memakai uang titipan: bantuan yang diberikan tanpa alasan tercatat
         * sama sulitnya dipertanggungjawabkan dengan penolakan tanpa alasan.
         */
        DB::statement('ALTER TABLE '.self::S.".assessments ADD CONSTRAINT assessments_decision_reason_check
            CHECK (
                decision = 'belum-diputuskan'
                OR (decided_by_name IS NOT NULL
                    AND decision_reason IS NOT NULL AND btrim(decision_reason) <> ''
                    AND decided_at IS NOT NULL)
            )");

        Schema::create(self::S.'.assessment_answers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('assessment_id')->constrained(self::S.'.assessments')->cascadeOnDelete();
            $table->string('category', 30);

            // Boleh kosong: kategori yang belum disurvei berbeda dari
            // kategori yang disurvei lalu tidak menemukan apa-apa.
            $table->unsignedBigInteger('criteria_id')->nullable();

            // Salinan, supaya kriteria yang dinonaktifkan atau diganti
            // kalimatnya tidak mengubah bunyi asesmen yang sudah diputuskan.
            $table->string('label', 120)->nullable();
            $table->smallInteger('weight')->nullable();

            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['assessment_id', 'category']);
        });

        DB::statement('ALTER TABLE '.self::S.'.assessment_answers
            ADD CONSTRAINT assessment_answers_criteria_fk
            FOREIGN KEY (criteria_id) REFERENCES '.self::S.'.assessment_criteria (id)');

        DB::statement('ALTER TABLE '.self::S.".assessment_answers ADD CONSTRAINT assessment_answers_category_check
            CHECK (category IN ('".implode("','", self::KATEGORI)."'))");

        // ------------------------------------------------ penyaluran

        Schema::create(self::S.'.disbursements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('disbursement_number', 24)->unique();

            /*
             * WAJIB. `ambil_dankes` Khanza cuma berisi tanggal, kategori, dan
             * jumlah — tanpa rujukan ke penerima sama sekali. Bantuan yang
             * tercatat tanpa penerima tidak bisa diaudit, tidak bisa
             * mendeteksi penerimaan ganda, dan tidak bisa menjawab
             * pertanyaan yang paling wajar: apakah keluarga ini pernah
             * dibantu.
             */
            $table->unsignedBigInteger('recipient_id');
            $table->unsignedBigInteger('assessment_id');

            $table->date('disbursed_on');
            $table->string('fund_source', 20);
            $table->decimal('amount', 16, 2);
            $table->string('purpose', 200);
            $table->text('note')->nullable();

            $table->unsignedBigInteger('disbursed_by')->nullable();
            $table->string('disbursed_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->index(['recipient_id', 'disbursed_on']);
            $table->index('fund_source');
        });

        foreach (['recipient_id' => 'recipients', 'assessment_id' => 'assessments'] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.disbursements
                ADD CONSTRAINT disbursements_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        DB::statement('ALTER TABLE '.self::S.".disbursements ADD CONSTRAINT disbursements_source_check
            CHECK (fund_source IN ('".implode("','", self::SUMBER_DANA)."'))");

        DB::statement('ALTER TABLE '.self::S.'.disbursements
            ADD CONSTRAINT disbursements_amount_check CHECK (amount > 0)');

        $this->seedAsnaf();
    }

    private function seedAsnaf(): void
    {
        $waktu = ['created_at' => now(), 'updated_at' => now()];

        foreach (self::ASNAF as $urut => [$kode, $nama, $uraian]) {
            DB::table(self::S.'.assessment_criteria')->insert([
                'category' => 'asnaf',
                'code' => 'ASNAF-'.$kode,
                'name' => $nama.' — '.$uraian,
                'weight' => null,
                'position' => $urut + 1,
                'is_active' => true,
            ] + $waktu);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.disbursements');
        Schema::dropIfExists(self::S.'.assessment_answers');
        Schema::dropIfExists(self::S.'.assessments');
        Schema::dropIfExists(self::S.'.recipients');
        Schema::dropIfExists(self::S.'.assessment_criteria');
        Schema::dropIfExists(self::S.'.number_sequences');

        DB::statement('DROP SCHEMA IF EXISTS '.self::S);
    }
};
