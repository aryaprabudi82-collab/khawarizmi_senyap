<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan keperawatan & keseimbangan cairan (domain M item E).
 *
 * Menaungi catatan_keperawatan_ralan, catatan_keperawatan_ranap,
 * catatan_pasien, balance_cairan (catatan_keseimbangan_cairan di skema
 * Khanza), dan catatan_cairan_hemodialisa.
 *
 * ===================== CATATAN KEPERAWATAN =====================
 *
 * Ini bagian yang sengaja ditunda dari item B. Proses keperawatan lengkap
 * adalah pengkajian-diagnosis-perencanaan-IMPLEMENTASI-EVALUASI; item B
 * membangun diagnosis dan perencanaannya, dan dua yang terakhir memang
 * tempatnya di sini — persis seperti pemisahan di Khanza.
 *
 * CATATAN BOLEH MENUNJUK DIAGNOSIS KEPERAWATANNYA, dan itu tambahan
 * terhadap Khanza yang catatannya berdiri sendiri (tanggal, jam, uraian).
 * Sifatnya OPSIONAL dan itu disengaja: tidak setiap catatan perawat
 * menindaklanjuti satu masalah tertentu — "pasien mengeluh pusing saat
 * bangun" adalah pengamatan, bukan pelaksanaan rencana. Mewajibkannya akan
 * memaksa perawat memilih masalah yang tidak nyata supaya catatannya bisa
 * disimpan.
 *
 * WAKTU DICATAT SEBAGAI SATU STEMPEL, bukan tanggal dan jam terpisah
 * seperti Khanza. Kunci (tanggal, jam, no_rawat) di sana membuat dua
 * catatan pada menit yang sama mustahil — padahal saat pasien memburuk,
 * beberapa catatan dalam satu menit justru yang paling mungkin terjadi.
 *
 * ===================== KESEIMBANGAN CAIRAN =====================
 *
 * SALDONYA DIHITUNG, TIDAK DISIMPAN. Khanza menyimpan kolom
 * `keseimbangan` di samping komponennya. Itu golongan kesalahan yang sama
 * seperti menyimpan sisa hutang atau sisa piutang, dan aturan proyek ini
 * sejak domain K sudah tegas: saldo tidak pernah disimpan. Bedanya, di
 * sini akibatnya bukan laporan keuangan yang meleset — keseimbangan cairan
 * yang salah pada pasien gagal jantung atau gagal ginjal adalah keputusan
 * klinis yang salah.
 *
 * SATU BARIS PER BUTIR, BUKAN SATU BARIS PER WAKTU DENGAN KOLOM PER BUTIR.
 * Khanza memasang kolom infus, transfusi, minum, urine, drain, NGT, IWL —
 * dan hemodialisa menuntut kolom lain lagi (sisa priming, wash out,
 * perdarahan, muntah), sehingga lahir tabel kedua yang isinya nyaris sama.
 * Dengan satu baris per butir, jenis cairan baru cukup baris baru di
 * master, dan hemodialisa memakai tabel yang sama.
 *
 * ARAH DISALIN DARI JENIS CAIRANNYA, tidak diterima dari pemanggil —
 * pola yang sama seperti kas di domain K item A. Arah yang boleh dikirim
 * terpisah membuka celah urine tercatat sebagai asupan, dan saldo yang
 * dihasilkan akan tampak wajar sambil sepenuhnya keliru.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        Schema::create(self::S . '.nursing_notes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            // Opsional — lihat catatan di atas.
            $table->foreignId('nursing_diagnosis_id')->nullable()
                ->constrained(self::S . '.nursing_diagnoses')->nullOnDelete();

            $table->string('kind', 20)->default('implementasi');
            $table->text('note');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();

            $table->index(['registration_id', 'recorded_at']);
            $table->index('nursing_diagnosis_id');
        });

        DB::statement('ALTER TABLE ' . self::S . ".nursing_notes
            ADD CONSTRAINT nursing_notes_kind_check
            CHECK (kind IN ('implementasi','evaluasi','pengamatan'))");

        /*
         * Catatan tingkat PASIEN, bukan kunjungan — mengikuti
         * catatan_pasien Khanza yang berkunci no_rkm_medis. Isinya hal yang
         * berlaku lintas kunjungan: pasien sulit diambil darah, keluarga
         * minta diagnosis tidak disampaikan langsung, dan semacamnya.
         * Menempelkannya pada kunjungan akan membuatnya hilang dari
         * pandangan pada kunjungan berikutnya — justru saat ia paling
         * dibutuhkan.
         */
        Schema::create(self::S . '.patient_notes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('patient_id');
            $table->string('patient_mrn', 30);

            $table->text('note');
            $table->boolean('is_alert')->default(false)
                ->comment('true bila harus menonjol di setiap layar pasien ini');

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['patient_id', 'is_alert']);
        });

        Schema::create(self::S . '.fluid_balance_entries', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('patient_id');

            $table->string('item_code', 40);
            $table->string('item_name', 100)->comment('Disalin saat dicatat');
            $table->string('direction', 10)->comment('masuk atau keluar; disalin dari jenis cairannya');

            $table->decimal('volume_ml', 10, 2);
            $table->string('note', 200)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();

            $table->index(['registration_id', 'recorded_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".fluid_balance_entries
            ADD CONSTRAINT fluid_balance_entries_direction_check
            CHECK (direction IN ('masuk','keluar'))");

        // Volume selalu POSITIF; arahnya yang menentukan tandanya. Volume
        // negatif membuat satu baris bisa membalik arah tanpa melanggar
        // satu aturan pun.
        DB::statement('ALTER TABLE ' . self::S . ".fluid_balance_entries
            ADD CONSTRAINT fluid_balance_entries_volume_check
            CHECK (volume_ml > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.fluid_balance_entries');
        Schema::dropIfExists(self::S . '.patient_notes');
        Schema::dropIfExists(self::S . '.nursing_notes');
    }
};
