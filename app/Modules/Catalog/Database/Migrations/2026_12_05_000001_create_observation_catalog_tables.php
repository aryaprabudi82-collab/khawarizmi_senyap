<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog pengukuran & panel observasi (domain M item D).
 *
 * Menaungi 12 kode catatan_observasi_* Khanza (ranap, igd, bayi, ruang OK,
 * ventilator, hemodialisa, kebidanan, post partum, CHBP, induksi
 * persalinan, restrain nonfarmakologi) berikut catatan_cek_gds.
 *
 * DIPERIKSA KE SKEMA KHANZA. catatan_observasi_igd dan
 * catatan_observasi_ranap berbentuk PERSIS SAMA: (no_rawat, tanggal, jam)
 * plus GCS, tekanan darah, nadi, laju napas, suhu, saturasi. Yang berbeda
 * cuma di unit mana ia dicatat. catatan_observasi_ventilator berbeda
 * isinya (mode, volume tidal, PEEP) tapi bentuknya sama: pengukuran
 * berulang pada satu titik waktu.
 *
 * KARENA ITU PENGUKURANNYA SUDAH PUNYA TEMPAT — clinical.observations,
 * yang sejak awal menyimpan kode, nilai, satuan, dan waktu. Yang belum ada
 * adalah PANELNYA: kumpulan pengukuran mana yang muncul di layar mana.
 * Membuat 12 tabel untuk 12 panel berarti setiap unit baru menuntut
 * migrasi basis data.
 *
 * RENTANG RUJUKAN MELEKAT PADA PANEL, BUKAN PADA KODE PENGUKURANNYA — dan
 * ini bukan kerapian, melainkan keselamatan. Laju napas 40 kali per menit
 * normal pada neonatus dan gawat pada dewasa. Kalau rentangnya menempel
 * pada kode "laju napas", seluruh bayi akan ditandai abnormal sepanjang
 * hari. Penanda abnormal yang selalu menyala melatih orang mengabaikannya,
 * dan yang terabaikan berikutnya adalah yang sungguhan. Itu pula sebabnya
 * Khanza memisahkan tabel per populasi; di sini yang dipisah cukup
 * panelnya.
 *
 * RENTANG DI PANEL BOLEH KOSONG, dan artinya "pakai rentang bawaan kode".
 * Kosong bukan berarti tidak ada rentang — itu dibedakan dari rentang yang
 * memang sengaja ditiadakan (berat badan, misalnya, tidak punya rentang
 * normal universal).
 */
return new class extends Migration
{
    private const S = 'catalog';

    public function up(): void
    {
        Schema::create(self::S . '.observation_codes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 60)->unique();
            $table->string('display', 150);
            $table->string('unit', 20)->nullable();

            // numerik atau teks: mode ventilator adalah pilihan, bukan angka,
            // dan memaksanya jadi angka membuat nilainya hilang artinya.
            $table->string('value_type', 12)->default('numeric');

            // Rentang BAWAAN, dipakai kalau panelnya tidak menyebut sendiri.
            $table->decimal('reference_low', 10, 2)->nullable();
            $table->decimal('reference_high', 10, 2)->nullable();

            $table->string('category', 40)->nullable()->comment('tanda-vital, ventilator, gula-darah, dst.');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category', 'is_active']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".observation_codes
            ADD CONSTRAINT observation_codes_value_type_check
            CHECK (value_type IN ('numeric','text'))");

        Schema::create(self::S . '.observation_panels', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 60)->unique();
            $table->string('name', 150);

            // Di mana panel ini dipakai. null berarti berlaku umum.
            $table->string('care_context', 40)->nullable()->comment('ranap, igd, ok, hemodialisa, ventilator, dst.');
            $table->string('age_group', 20)->nullable()->comment('neonatus, bayi, anak, dewasa');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['care_context', 'is_active']);
        });

        Schema::create(self::S . '.observation_panel_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('observation_panel_id')->constrained(self::S . '.observation_panels')->cascadeOnDelete();
            $table->foreignId('observation_code_id')->constrained(self::S . '.observation_codes');

            $table->unsignedSmallInteger('sequence')->default(1);
            $table->boolean('is_required')->default(false);

            // Rentang khusus panel ini — lihat catatan keselamatan di atas.
            $table->decimal('reference_low', 10, 2)->nullable();
            $table->decimal('reference_high', 10, 2)->nullable();

            $table->timestampsTz();

            $table->unique(['observation_panel_id', 'observation_code_id']);
            $table->index(['observation_panel_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.observation_panel_items');
        Schema::dropIfExists(self::S . '.observation_panels');
        Schema::dropIfExists(self::S . '.observation_codes');
    }
};
