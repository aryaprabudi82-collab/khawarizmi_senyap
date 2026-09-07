<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master imunisasi & jenis cacat fisik (domain M item S).
 *
 * Menaungi master_imunisasi dan cacat_fisik.
 *
 * MASTER IMUNISASI DIPERLUAS. master_imunisasi Khanza hanya punya kode
 * dan nama. Yang ditambahkan di sini bukan hiasan: jumlah dosis dan
 * jarak antar dosis menentukan kapan dosis berikutnya jatuh tempo, dan
 * tanpa keduanya jadwal imunisasi seorang anak tidak bisa dihitung
 * sistem — harus diingat orang.
 *
 * KODE KFA IKUT DITAMPUNG supaya pelaporan ke SATUSEHAT tidak menuntut
 * tabel pemetaan keempat; Khanza menyediakan satu_sehat_mapping_vaksin
 * terpisah, dan pemetaan yang hidup di tabel lain adalah satu tempat
 * lagi yang bisa tertinggal saat vaksin baru ditambahkan.
 *
 * JENIS CACAT FISIK JADI MASTER DI SINI, sedangkan pasien mana punya
 * yang mana ada di clinical — dan itu menutup separuh yang hilang:
 * cacat_fisik Khanza HANYA master (id, nama_cacat), tanpa satu pun
 * tabel yang mencatat pasien mana punya cacat yang mana. Daftar jenis
 * tanpa pemakaiannya tidak menjawab pertanyaan apa pun.
 *
 * KATEGORI TEMPLATE 'triase' DITAMBAHKAN, bukan enam tabel master.
 * master_triase_pemeriksaan berikut master_triase_skala1 sampai skala5
 * sebenarnya satu INSTRUMEN BERSKALA: pemeriksaan dikali lima tingkat,
 * masing-masing dengan uraian pengkajiannya. Bentuk itu persis yang
 * sudah ditangani template formulir sejak item A. Isi skalanya TIDAK
 * disemai: kriteria triase adalah keputusan komite medik RSP UI, sama
 * seperti empat puluh kode formulir lain yang sengaja dibiarkan kosong.
 */
return new class extends Migration
{
    private const S = 'catalog';

    private const KATEGORI_LAMA = [
        'asesmen-medis', 'asesmen-keperawatan', 'skrining',
        'pengkajian-lanjutan', 'checklist', 'catatan', 'hasil-pemeriksaan',
    ];

    private const KATEGORI_BARU = [
        'asesmen-medis', 'asesmen-keperawatan', 'skrining',
        'pengkajian-lanjutan', 'checklist', 'catatan', 'hasil-pemeriksaan', 'triase',
    ];

    public function up(): void
    {
        $this->createImmunisationTypes();
        $this->createDisabilityTypes();
        $this->gantiKategoriCheck(self::KATEGORI_BARU);
    }

    public function down(): void
    {
        $this->gantiKategoriCheck(self::KATEGORI_LAMA);
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_disability_type');
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_immunisation_type');
        Schema::dropIfExists(self::S.'.disability_types');
        Schema::dropIfExists(self::S.'.immunisation_types');
    }

    private function createImmunisationTypes(): void
    {
        Schema::create(self::S.'.immunisation_types', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('disease_prevented', 200)->nullable();

            // Tanpa keduanya, jadwal dosis berikutnya tidak bisa dihitung.
            $table->unsignedSmallInteger('total_doses')->nullable()
                ->comment('Jumlah dosis lengkap; kosong untuk vaksin yang diulang tanpa batas seperti influenza');
            $table->unsignedSmallInteger('interval_days')->nullable()
                ->comment('Jarak minimum antar dosis');

            $table->string('route', 30)->nullable()->comment('intramuskular, subkutan, intradermal, oral');
            $table->string('kfa_code', 30)->nullable()
                ->comment('Pemetaan SATUSEHAT ditampung di sini, bukan tabel pemetaan terpisah');

            $table->boolean('is_national_programme')->default(false)
                ->comment('Masuk program imunisasi nasional; menentukan kewajiban pelaporannya');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['is_national_programme', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.".immunisation_types
            ADD CONSTRAINT immunisation_types_route_check
            CHECK (route IS NULL
                   OR route IN ('intramuskular','subkutan','intradermal','oral','intranasal'))");

        DB::statement('ALTER TABLE '.self::S.'.immunisation_types
            ADD CONSTRAINT immunisation_types_doses_check
            CHECK (total_doses IS NULL OR total_doses >= 1)');

        DB::statement('CREATE VIEW '.self::S.'.v_immunisation_type AS
            SELECT code, name, disease_prevented, total_doses, interval_days,
                   route, kfa_code, is_national_programme, is_active
              FROM '.self::S.'.immunisation_types');
    }

    private function createDisabilityTypes(): void
    {
        Schema::create(self::S.'.disability_types', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('category', 30)->default('lainnya')
                ->comment('fisik, sensorik, intelektual, mental, ganda, lainnya');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.".disability_types
            ADD CONSTRAINT disability_types_category_check
            CHECK (category IN ('fisik','sensorik','intelektual','mental','ganda','lainnya'))");

        DB::statement('CREATE VIEW '.self::S.'.v_disability_type AS
            SELECT code, name, category, is_active
              FROM '.self::S.'.disability_types');
    }

    /**
     * Nama constraint dibaca dari pg_constraint, bukan ditebak — pelajaran
     * yang sudah tercatat sejak item G.
     *
     * @param  array<int, string>  $nilai
     */
    private function gantiKategoriCheck(array $nilai): void
    {
        foreach (DB::select("SELECT conname FROM pg_constraint
            WHERE conrelid = 'catalog.form_templates'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%category%'") as $constraint) {
            DB::statement("ALTER TABLE catalog.form_templates DROP CONSTRAINT {$constraint->conname}");
        }

        DB::statement("ALTER TABLE catalog.form_templates
            ADD CONSTRAINT form_templates_category_check
            CHECK (category IN ('".implode("','", $nilai)."'))");
    }
};
