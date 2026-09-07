<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Template formulir asesmen & skrining (domain M item A).
 *
 * DUDUK PERKARANYA. Domain M Khanza berisi 244 kode, dan dua kelompok
 * terbesarnya adalah hal yang sama diulang-ulang:
 *
 *   ~40 kode "penilaian awal medis/keperawatan" — satu untuk tiap
 *   spesialisasi (mata, THT, jantung, kulit, orthopedi, dan seterusnya),
 *   dan
 *
 *   ~35 kode "skrining" — TBC, anemia, gizi, kanker payudara, CURB-65,
 *   PUMA, dan seterusnya.
 *
 * Isinya berbeda, tapi PERBUATANNYA satu: mengisi formulir terstruktur
 * tentang seorang pasien pada satu kunjungan, lalu menyimpannya sebagai
 * bagian rekam medis. Membangun 75 tabel dan 75 layar untuk itu berarti
 * 75 tempat yang harus diubah setiap kali aturan rekam medis berubah —
 * dan setiap poliklinik baru menuntut migrasi basis data.
 *
 * KARENA ITU FORMULIRNYA JADI DATA, BUKAN KODE. Template menyimpan
 * pertanyaan dan cara menilainya; jawabannya disimpan di konteks clinical.
 * Menambah "Awal Medis Ralan Onkologi" cukup satu baris template, tanpa
 * migrasi, tanpa layar baru.
 *
 * VERSI TEMPLATE ADALAH BAGIAN DARI IDENTITASNYA, bukan kolom biasa.
 * Formulir asesmen direvisi terus — pertanyaan ditambah, skala diubah,
 * ambang skor digeser. Kalau versi lama ditimpa, asesmen yang diisi tahun
 * lalu akan DIBACA ULANG dengan aturan tahun ini, dan yang tertulis di
 * rekam medis berubah tanpa ada yang menyentuhnya. Karena itu tiap versi
 * disimpan sebagai baris tersendiri dan yang lama tidak pernah dihapus.
 *
 * AMBANG SKOR IKUT DI TEMPLATE, bukan di kode program. Ambang risiko
 * jatuh, batas skor gizi, dan tafsir CURB-65 ditetapkan pedoman klinis
 * yang berubah tanpa memberi tahu pemrogram. Menaruhnya di kode berarti
 * setiap revisi pedoman menuntut penerapan ulang aplikasi.
 */
return new class extends Migration
{
    private const S = 'catalog';

    /**
     * Kelompok formulir. Menentukan siapa yang mengisinya dan di layar
     * mana ia muncul — bukan sekadar label.
     */
    private const KATEGORI = [
        'asesmen-medis',        // penilaian awal medis per spesialisasi
        'asesmen-keperawatan',  // penilaian awal keperawatan
        'skrining',             // instrumen skrining (TBC, gizi, kanker, dst.)
        'pengkajian-lanjutan',  // risiko jatuh lanjutan, restrain, nyeri ulang
        'checklist',            // kriteria masuk/keluar unit, keselamatan bedah
        'catatan',             // catatan observasi terstruktur
    ];

    public function up(): void
    {
        Schema::create(self::S . '.form_templates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 60)->comment('Kode template, mis. asesmen-medis-mata');
            $table->unsignedSmallInteger('version')->default(1);

            $table->string('name', 150);
            $table->string('category', 30);

            // Spesialisasi/unit yang memakainya. null berarti berlaku umum.
            $table->string('specialty', 60)->nullable();

            // Kelompok umur sasaran, karena formulir anak dan dewasa memang
            // berbeda isinya dan salah pakai menghasilkan penilaian yang
            // tidak berarti (skala nyeri anak tidak berlaku untuk dewasa).
            $table->string('age_group', 20)->nullable()->comment('neonatus, anak, dewasa, geriatri');

            /*
             * Isi formulir: daftar bagian, tiap bagian berisi pertanyaan
             * berikut jenis jawaban, pilihan, dan bobot skornya. Disimpan
             * sebagai JSON karena bentuknya memang berbeda-beda tiap
             * formulir — memaksakannya jadi kolom akan melahirkan tabel
             * dengan ratusan kolom yang mayoritasnya kosong.
             */
            $table->json('sections');

            /*
             * Aturan penafsiran skor: ambang dan artinya. Ikut di template,
             * bukan di kode program — lihat catatan di atas.
             */
            $table->json('scoring')->nullable();

            $table->text('note')->nullable()->comment('Rujukan pedoman klinis yang mendasarinya');

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['code', 'version']);
            $table->index(['category', 'is_active']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".form_templates
            ADD CONSTRAINT form_templates_category_check
            CHECK (category IN ('" . implode("','", self::KATEGORI) . "'))");

        // Hanya SATU versi yang aktif per kode. Dua versi aktif berarti dua
        // petugas mengisi formulir berbeda untuk hal yang sama, dan hasilnya
        // tidak bisa dibandingkan.
        DB::statement('CREATE UNIQUE INDEX form_template_aktif_unique
            ON ' . self::S . '.form_templates (code) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.form_templates');
    }
};
