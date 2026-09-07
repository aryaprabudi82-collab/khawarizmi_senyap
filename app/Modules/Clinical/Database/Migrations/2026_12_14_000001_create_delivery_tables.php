<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persalinan & bayi baru lahir (domain M item J).
 *
 * Menaungi catatan_persalinan, pasien_bayi, dan penilaian_bayi_baru_lahir.
 *
 * TEMUAN TERBERAT SEJAUH INI: BAYI KEDUA TIDAK PUNYA TEMPAT.
 * catatan_persalinan Khanza menyediakan SATU kolom untuk tiap hal
 * tentang bayi — anak enum('Laki-laki','Perempuan'), status_lahir
 * enum('Hidup','Mati'), apgar_score, bb, pb, kelainan. Pada kelahiran
 * kembar, bayi kedua sama sekali tidak bisa dicatat: tidak ada
 * kolomnya, dan tidak ada pesan apa pun yang memberi tahu bidan bahwa
 * datanya hilang.
 *
 * Yang membuatnya lebih terang: penilaian_bayi_baru_lahir di sistem
 * yang sama punya kehamilan enum('Tunggal','Kembar'). Jadi Khanza tahu
 * kembar itu ada, dan tetap tidak menyediakan tempat untuk bayi
 * keduanya di catatan persalinannya.
 *
 * Maka di sini BAYI ADALAH BARIS, BUKAN KOLOM. Satu persalinan boleh
 * punya berapa pun bayi, masing-masing dengan urutan lahir, jenis
 * kelamin, keadaan lahir, dan ukurannya sendiri.
 *
 * APGAR DIPECAH JADI KOMPONENNYA. Khanza menyimpan apgar_score
 * varchar(20) — lima komponen pada tiga menit penilaian dijejalkan ke
 * dua puluh karakter. Akibatnya tidak ada yang bisa memeriksa apakah
 * angkanya benar, dan tidak ada yang bisa tahu komponen mana yang
 * rendah — padahal itulah yang menentukan tindakan resusitasi.
 * Di sini tiap komponen 0-2 tersimpan sendiri dan JUMLAHNYA DIHITUNG.
 *
 * DUA NILAI TURUNAN KHANZA TIDAK IKUT DIBUAT.
 * waktu_persalinan_jumlah berdiri di samping kala 1, 2, dan 3;
 * darah_keluar_jumlah berdiri di samping kala 2, 3, dan 4. Keduanya
 * bisa berbeda dari penjumlahan bagiannya, dan yang kedua berbahaya:
 * ambang perdarahan pascasalin 500 mL diputuskan dari angka itu.
 * Keduanya dihitung, tidak disimpan — aturan yang sama dengan balans
 * cairan.
 *
 * KALA PERSALINAN BOLEH JADI KOLOM, DIAGNOSIS TIDAK — dan bedanya
 * perlu disebut supaya alasannya tidak terbaca sebagai selera. Kala
 * persalinan ada empat, tertutup, dan tidak akan bertambah menjadi
 * lima; diagnosis seorang pasien tidak punya batas atas. Kolom tetap
 * hanya boleh untuk yang pertama.
 *
 * WAKTU KETUBAN PECAH JADI SATU TIMESTAMP. Khanza memecahnya menjadi
 * jam_ketuban_pecah varchar(4) dan menit_ketuban_pecah varchar(4) —
 * dua teks yang tidak bisa dikurangkan. Padahal justru selisih antara
 * pecah ketuban dan kelahiran yang menentukan risiko infeksi, dan
 * itulah yang perlu bisa dihitung.
 *
 * TANDA VITAL IBU TIDAK DIDUPLIKASI DI SINI. catatan_persalinan
 * Khanza memuat td, nadi, rr, dan suhu; sejak domain M item D rumah
 * sakit ini sudah punya panel observasi untuk itu, dan menaruh salinan
 * kedua melahirkan dua tekanan darah yang bisa berbeda pada jam yang
 * sama.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        $this->createDeliveries();
        $this->createBabies();
        $this->createApgar();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.delivery_baby_apgar_scores');
        Schema::dropIfExists(self::S.'.delivery_babies');
        Schema::dropIfExists(self::S.'.deliveries');
    }

    private function createDeliveries(): void
    {
        Schema::create(self::S.'.deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Kunjungan IBU. Bayi punya kunjungannya sendiri setelah
            // didaftarkan, dan tautannya ada di delivery_babies.
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('registration_number', 30);
            $table->string('patient_mrn', 30);
            $table->string('patient_name', 150);

            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable()
                ->comment('Kosong selama persalinan berlangsung; wajib saat difinalkan');

            $table->unsignedBigInteger('attending_practitioner_id')->nullable();
            $table->string('attending_practitioner_name', 150)->nullable();
            $table->string('midwife_name', 150)->nullable();

            $table->string('delivery_method', 40)->nullable()
                ->comment('spontan, vakum, forsep, seksio-sesarea, sungsang');

            $table->unsignedSmallInteger('gravida')->nullable();
            $table->unsignedSmallInteger('para')->nullable();
            $table->unsignedSmallInteger('abortus')->nullable();
            $table->string('gestational_age', 30)->nullable();

            // Satu timestamp, bukan dua teks — lihat catatan kelas.
            $table->timestampTz('membrane_ruptured_at')->nullable();
            $table->string('amniotic_fluid', 60)->nullable()->comment('ketuban: jernih, keruh, mekonium');

            // Empat kala, tertutup dan tidak akan bertambah. TIDAK ada
            // kolom jumlah: dihitung.
            $table->unsignedSmallInteger('stage1_minutes')->nullable();
            $table->unsignedSmallInteger('stage2_minutes')->nullable();
            $table->unsignedSmallInteger('stage3_minutes')->nullable();
            $table->unsignedSmallInteger('stage4_minutes')->nullable();

            $table->unsignedInteger('blood_loss_stage2_ml')->nullable();
            $table->unsignedInteger('blood_loss_stage3_ml')->nullable();
            $table->unsignedInteger('blood_loss_stage4_ml')->nullable();

            $table->string('perineum', 20)->nullable()->comment('utuh, ruptur, episiotomi');
            $table->string('perineum_degree', 10)->nullable()->comment('Derajat ruptur: 1, 2, 3a, 3b, 3c, 4');
            $table->unsignedSmallInteger('outer_sutures')->nullable();
            $table->unsignedSmallInteger('inner_sutures')->nullable();

            $table->string('placenta_delivery', 40)->nullable()->comment('spontan, manual');
            $table->string('uterine_contraction', 100)->nullable();
            $table->string('vaginal_bleeding', 100)->nullable()->comment('ppv');
            $table->text('medication')->nullable();
            $table->text('note')->nullable();

            $table->string('status', 20)->default('draf');
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        DB::statement('ALTER TABLE '.self::S.".deliveries
            ADD CONSTRAINT deliveries_status_check
            CHECK (status IN ('draf','final','dibatalkan'))");

        DB::statement('ALTER TABLE '.self::S.".deliveries
            ADD CONSTRAINT deliveries_perineum_check
            CHECK (perineum IS NULL OR perineum IN ('utuh','ruptur','episiotomi'))");

        DB::statement('ALTER TABLE '.self::S.'.deliveries
            ADD CONSTRAINT deliveries_period_check
            CHECK (ended_at IS NULL OR ended_at >= started_at)');

        // Ketuban tidak bisa pecah setelah persalinan selesai.
        DB::statement('ALTER TABLE '.self::S.'.deliveries
            ADD CONSTRAINT deliveries_membrane_check
            CHECK (membrane_ruptured_at IS NULL OR ended_at IS NULL OR membrane_ruptured_at <= ended_at)');

        DB::statement('ALTER TABLE '.self::S.".deliveries
            ADD CONSTRAINT deliveries_final_check
            CHECK (status <> 'final' OR (finalized_at IS NOT NULL AND ended_at IS NOT NULL))");

        // Satu kunjungan satu catatan persalinan.
        DB::statement('CREATE UNIQUE INDEX deliveries_one_per_registration
            ON '.self::S.".deliveries (registration_id)
            WHERE status <> 'dibatalkan' AND deleted_at IS NULL");
    }

    private function createBabies(): void
    {
        Schema::create(self::S.'.delivery_babies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('delivery_id');
            $table->unsignedSmallInteger('birth_order')->comment('1, 2, 3 — kembar tiga pun muat');

            $table->timestampTz('born_at');
            $table->string('sex', 15)->comment('L, P, atau tidak-jelas — ambiguitas genital adalah temuan nyata');
            $table->string('birth_status', 20)->comment('hidup, lahir-mati');

            // Angka, bukan varchar seperti pasien_bayi Khanza: ukuran bayi
            // dibandingkan dengan kurva pertumbuhan, dan teks tidak bisa
            // dibandingkan.
            $table->unsignedInteger('weight_grams')->nullable();
            $table->decimal('length_cm', 5, 1)->nullable();
            $table->decimal('head_circumference_cm', 5, 1)->nullable();
            $table->decimal('chest_circumference_cm', 5, 1)->nullable();
            $table->decimal('abdominal_circumference_cm', 5, 1)->nullable();

            $table->string('abnormality', 200)->nullable();
            $table->text('note')->nullable();

            // Terisi setelah bayinya didaftarkan sebagai pasien tersendiri.
            // Referensi longgar: identitas pasien milik konteks identity.
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('patient_mrn', 30)->nullable();

            $table->timestampsTz();

            $table->index('delivery_id');
            $table->index('patient_id');
        });

        DB::statement('ALTER TABLE '.self::S.".delivery_babies
            ADD CONSTRAINT delivery_babies_sex_check
            CHECK (sex IN ('L','P','tidak-jelas'))");

        DB::statement('ALTER TABLE '.self::S.".delivery_babies
            ADD CONSTRAINT delivery_babies_status_check
            CHECK (birth_status IN ('hidup','lahir-mati'))");

        DB::statement('ALTER TABLE '.self::S.'.delivery_babies
            ADD CONSTRAINT delivery_babies_order_check
            CHECK (birth_order >= 1)');

        // Urutan lahir tidak boleh kembar dua kali dalam satu persalinan.
        DB::statement('CREATE UNIQUE INDEX delivery_babies_order
            ON '.self::S.'.delivery_babies (delivery_id, birth_order)');
    }

    private function createApgar(): void
    {
        Schema::create(self::S.'.delivery_baby_apgar_scores', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('baby_id');
            $table->unsignedSmallInteger('minute')->comment('1, 5, atau 10');

            // Lima komponen, masing-masing 0-2. Yang menentukan tindakan
            // resusitasi adalah komponen mana yang rendah, bukan jumlahnya.
            $table->unsignedSmallInteger('appearance')->comment('Warna kulit');
            $table->unsignedSmallInteger('pulse')->comment('Denyut jantung');
            $table->unsignedSmallInteger('grimace')->comment('Refleks terhadap rangsang');
            $table->unsignedSmallInteger('activity')->comment('Tonus otot');
            $table->unsignedSmallInteger('respiration')->comment('Usaha napas');

            // TIDAK ADA KOLOM JUMLAH — dihitung dari lima komponen di atas.

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->index('baby_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.delivery_baby_apgar_scores
            ADD CONSTRAINT delivery_baby_apgar_minute_check
            CHECK (minute IN (1, 5, 10))');

        DB::statement('ALTER TABLE '.self::S.'.delivery_baby_apgar_scores
            ADD CONSTRAINT delivery_baby_apgar_range_check
            CHECK (appearance BETWEEN 0 AND 2
                   AND pulse BETWEEN 0 AND 2
                   AND grimace BETWEEN 0 AND 2
                   AND activity BETWEEN 0 AND 2
                   AND respiration BETWEEN 0 AND 2)');

        DB::statement('CREATE UNIQUE INDEX delivery_baby_apgar_minute
            ON '.self::S.'.delivery_baby_apgar_scores (baby_id, minute)');
    }
};
