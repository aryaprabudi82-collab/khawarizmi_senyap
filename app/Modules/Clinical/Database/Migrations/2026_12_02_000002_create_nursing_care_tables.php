<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asuhan keperawatan pasien (domain M item B).
 *
 * Pasangan transaksional dari master masalah & rencana keperawatan.
 * Menaungi bagian "masalah" dan "rencana" pada seluruh kode
 * penilaian_awal_keperawatan_* Khanza — yang di sana berupa dua tabel anak
 * untuk SETIAP jenis asesmen keperawatan (ralan, ranap, igd, gigi, mata,
 * bayi, neonatus, kebidanan, psikiatri, geriatri).
 *
 * SATU PERBAIKAN TERHADAP KHANZA, DAN DASARNYA DARI KHANZA SENDIRI.
 * Di Khanza, tabel penilaian_awal_keperawatan_ralan_masalah dan
 * ..._rencana keduanya anak langsung dari asesmen: kuncinya (no_rawat,
 * kode). Akibatnya rencana keperawatan bisa dipilih TANPA masalah yang
 * mendasarinya ikut dipilih — intervensi tanpa indikasi, dan tidak ada
 * yang menahannya.
 *
 * Padahal master-nya sendiri sudah menyatakan hierarkinya:
 * master_rencana_keperawatan punya foreign key ke
 * master_masalah_keperawatan. Jadi yang dilakukan di sini bukan menyimpang
 * dari Khanza, melainkan MENEGAKKAN aturan yang Khanza sudah tuliskan di
 * master tapi tidak ditegakkan di transaksinya: rencana melekat pada
 * DIAGNOSIS KEPERAWATAN yang dipilih, bukan pada lembar asesmennya.
 *
 * ISI DIBEKUKAN, SEPERTI DI SELURUH REKAM MEDIS INI. Nama masalah dan
 * bunyi rencana disalin saat dipilih. Master keperawatan direvisi mengikuti
 * SDKI/SIKI, dan asuhan yang sudah ditulis perawat tidak boleh ikut
 * berubah kalimatnya.
 *
 * EVALUASI TIDAK DI SINI. Proses keperawatan lengkap adalah
 * pengkajian-diagnosis-perencanaan-implementasi-evaluasi; yang dua
 * terakhir dicatat di catatan keperawatan (kode catatan_keperawatan_ralan
 * dan _ranap), sama seperti pemisahan di Khanza. Menyatukannya di sini
 * akan membuat satu baris rencana punya banyak evaluasi tanpa tempat.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.nursing_diagnoses', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Melekat pada lembar asesmen keperawatan (form_responses dari
            // item A), bukan langsung pada kunjungan: satu kunjungan bisa
            // punya asesmen awal DAN asesmen lanjutan, dan masalah yang
            // ditemukan di masing-masing bukan hal yang sama.
            $table->foreignId('form_response_id')->constrained(self::S . '.form_responses')->cascadeOnDelete();

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            // Kode DAN namanya — disalin, bukan dirujuk.
            $table->string('problem_code', 20);
            $table->string('problem_name', 200);
            $table->string('specialty', 40)->nullable();
            $table->string('standard_code', 20)->nullable();

            // Urutan prioritas: masalah keperawatan memang diurutkan, dan
            // yang pertama menentukan intervensi mana yang didahulukan saat
            // waktu perawat terbatas.
            $table->unsignedSmallInteger('priority')->default(1);

            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampsTz();

            // Satu masalah hanya boleh dipilih sekali per lembar asesmen.
            $table->unique(['form_response_id', 'problem_code']);
            $table->index(['registration_id']);
            $table->index(['patient_id', 'problem_code']);
        });

        Schema::create(self::S . '.nursing_care_plan_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Melekat pada DIAGNOSIS-nya, bukan pada lembar asesmen —
            // inilah aturan yang ditegakkan di sini. Lihat catatan di atas.
            $table->foreignId('nursing_diagnosis_id')
                ->constrained(self::S . '.nursing_diagnoses')
                ->cascadeOnDelete();

            $table->string('plan_code', 20);
            $table->text('plan')->comment('Bunyi rencana, disalin saat dipilih');
            $table->string('standard_code', 20)->nullable();

            $table->string('status', 20)->default('direncanakan');
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->unique(['nursing_diagnosis_id', 'plan_code']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".nursing_care_plan_items
            ADD CONSTRAINT nursing_care_plan_items_status_check
            CHECK (status IN ('direncanakan','dikerjakan','dihentikan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.nursing_care_plan_items');
        Schema::dropIfExists(self::S . '.nursing_diagnoses');
    }
};
