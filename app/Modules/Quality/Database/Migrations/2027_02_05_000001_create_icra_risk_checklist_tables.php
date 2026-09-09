<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identifikasi risiko & pemenuhan persyaratan ICRA (domain R item B).
 *
 * Empat kode: identifikasi risiko infeksi, keselamatan, kebakaran, dan
 * utilitas.
 *
 * KEEMPATNYA DAFTAR PERIKSA, BUKAN TINGKATAN.
 *
 * Tabel Khanza `pcra_icra_identifkasi_risiko_infeksi` dan tiga saudaranya
 * berisi kode + nama RISIKO — artinya penilai mencentang risiko mana yang
 * ada pada proyek ini. Sistem kita menciutkan masing-masing jadi satu
 * kolom tingkatan (`infection_risk_level` dan seterusnya).
 *
 * Satu kolom tingkatan mencatat KESIMPULAN tanpa mencatat dasarnya. Itu
 * bentuk kekurangan yang sama persis dengan persetujuan tindakan pada
 * domain P: tersimpan bahwa seseorang menyimpulkan "risiko sedang", tidak
 * tersimpan apa yang ia periksa untuk sampai ke situ. Saat ada kejadian,
 * pertanyaannya bukan "berapa tingkat risikonya" melainkan "apakah risiko
 * ini sempat dipertimbangkan" — dan kolom tingkatan tidak bisa
 * menjawabnya.
 *
 * BELUM DIPERIKSA ADALAH NULL, BUKAN FALSE. Daftar periksa yang memakai
 * kotak centang biasa cuma punya dua keadaan: tercentang dan tidak. Yang
 * tidak tercentang lalu terbaca "risiko ini tidak ada" — padahal bisa
 * saja tidak ada yang melihatnya. Bedanya menentukan: ICRA yang berbunyi
 * "tidak ada risiko kebakaran" tanpa ada yang memeriksanya adalah dokumen
 * yang akan dikutip setelah kebakaran terjadi. Karena itu tiga keadaan —
 * null (belum diperiksa), false (diperiksa, tidak ada), true (ada).
 *
 * PERSYARATAN DISALIN, TIDAK DIRUJUK. Persyaratan per kelas dibekukan ke
 * dalam pengkajian saat kelasnya ditetapkan. Kalau dirujuk, revisi SPO
 * tahun depan akan mengubah daftar persyaratan proyek tahun ini —
 * termasuk proyek yang sudah selesai dan sudah dinyatakan memenuhi
 * seluruhnya. Pola yang sama dengan butir persetujuan pada domain P dan
 * versi template asesmen pada domain M.
 *
 * PENGKAJIAN TIDAK BISA DITUTUP SELAMA ADA PERSYARATAN YANG BELUM
 * DIJAWAB, TAPI BOLEH DITUTUP DENGAN PERSYARATAN YANG TIDAK TERPENUHI —
 * asal penyimpangannya berketerangan. Ketaksimetrisan yang sama seperti
 * persetujuan/penolakan pada domain P: menutup pengkajian dengan
 * persyaratan yang belum dijawab berarti tidak ada yang memeriksa apakah
 * barrier benar-benar terpasang; sedangkan penyimpangan yang tercatat
 * berikut alasannya adalah catatan jujur yang justru berguna.
 */
return new class extends Migration
{
    private const S = 'quality';

    /**
     * Empat kelompok risiko yang diidentifikasi. Batasnya pedoman ICRA —
     * empat kode Khanza yang terpisah, di sini satu tabel dengan kolom
     * kategori karena bentuk isiannya identik dan memisahkannya jadi empat
     * tabel berarti empat kali menulis mekanisme yang sama.
     */
    private const KATEGORI = ['infeksi', 'keselamatan', 'kebakaran', 'utilitas'];

    public function up(): void
    {
        /*
         * Daftar butir risiko. LAHIR KOSONG: butirnya disusun IPCN RSP UI
         * dari pedoman dan pengalaman mereka sendiri, dan mengarangnya
         * berarti menerbitkan daftar periksa resmi yang tidak pernah
         * ditinjau siapa pun — lalu proyek dinilai lengkap karena seluruh
         * butir karangan itu tercentang.
         */
        Schema::create(self::S.'.icra_risk_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('category', 20);
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.".icra_risk_items ADD CONSTRAINT icra_risk_items_category_check
            CHECK (category IN ('".implode("','", self::KATEGORI)."'))");

        // ------------------------------- hasil identifikasi per kajian

        Schema::create(self::S.'.icra_assessment_risks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('assessment_id')->constrained(self::S.'.icra_assessments')->cascadeOnDelete();
            $table->unsignedBigInteger('risk_item_id');

            // SALINAN, supaya butir yang dinonaktifkan atau diubah kalimatnya
            // tidak mengubah bunyi kajian yang sudah ditandatangani.
            $table->string('category', 20);
            $table->string('label', 200);

            /*
             * TIGA KEADAAN:
             *   null  — belum diperiksa
             *   false — diperiksa, risikonya tidak ada
             *   true  — risikonya ada
             */
            $table->boolean('present')->nullable();
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->unique(['assessment_id', 'risk_item_id']);
            $table->index(['assessment_id', 'category']);
        });

        DB::statement('ALTER TABLE '.self::S.'.icra_assessment_risks
            ADD CONSTRAINT icra_assessment_risks_item_fk
            FOREIGN KEY (risk_item_id) REFERENCES '.self::S.'.icra_risk_items (id)');

        DB::statement('ALTER TABLE '.self::S.".icra_assessment_risks ADD CONSTRAINT icra_assessment_risks_category_check
            CHECK (category IN ('".implode("','", self::KATEGORI)."'))");

        // ------------------------- persyaratan yang dibekukan per kajian

        Schema::create(self::S.'.icra_assessment_requirements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('assessment_id')->constrained(self::S.'.icra_assessments')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');

            // Salinan kalimat SPO saat kelas ditetapkan — bukan rujukan.
            $table->text('requirement');

            /*
             * null  — belum dijawab
             * false — tidak dipenuhi (penyimpangan; wajib berketerangan)
             * true  — dipenuhi
             */
            $table->boolean('fulfilled')->nullable();
            $table->text('note')->nullable();

            $table->timestampTz('verified_at')->nullable();
            $table->string('verified_by_name', 150)->nullable();

            $table->timestampsTz();

            $table->unique(['assessment_id', 'position']);
        });

        /*
         * Penyimpangan wajib berketerangan. Persyaratan yang DIPENUHI
         * berbukti pada barrier yang terpasang; persyaratan yang TIDAK
         * dipenuhi tidak meninggalkan apa pun selain catatan ini — dan
         * itulah baris yang paling perlu bisa dibaca kembali.
         */
        DB::statement('ALTER TABLE '.self::S.".icra_assessment_requirements
            ADD CONSTRAINT icra_assessment_requirements_deviation_check
            CHECK (fulfilled IS DISTINCT FROM false OR (note IS NOT NULL AND btrim(note) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.icra_assessment_requirements');
        Schema::dropIfExists(self::S.'.icra_assessment_risks');
        Schema::dropIfExists(self::S.'.icra_risk_items');
    }
};
