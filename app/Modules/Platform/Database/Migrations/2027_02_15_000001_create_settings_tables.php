<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan aplikasi & identitas institusi (domain U, item A).
 *
 * SATU BENTUK CACAT MENGULANG DI SELURUH DOMAIN U: tabel pengaturan satu
 * baris tanpa kunci dan tanpa riwayat. `set_embalase` tidak punya primary
 * key sama sekali; `set_keterlambatan` bahkan MyISAM. Akibatnya bukan
 * kerapian: begitu embalase diubah dari 500 jadi 1.000, tidak ada satu pun
 * cara menjawab "sejak kapan" — padahal jawaban itulah yang menentukan
 * apakah tagihan bulan lalu benar. Di sini setiap pengaturan punya kunci,
 * setiap perubahan punya barisnya sendiri, dan setiap nilai punya tanggal
 * mulai berlaku.
 *
 * IDENTITAS RUMAH SAKIT TIDAK DIKUNCI OLEH NAMANYA SENDIRI. `setting`
 * Khanza memakai `nama_instansi` sebagai PRIMARY KEY. Artinya mengganti
 * nama rumah sakit tidak mengubah rumah sakitnya — ia MELAHIRKAN rumah
 * sakit kedua, dan yang lama tetap ada di sana dengan seluruh rujukan yang
 * menempel padanya. Nama adalah atribut, bukan identitas; rumah sakit yang
 * berganti nama tetap rumah sakit yang sama, dan sistem yang tidak bisa
 * menyatakan itu akan pecah persis pada hari nama itu berubah.
 *
 * LOGO TIDAK DISIMPAN DI DALAM BARIS PENGATURAN. Khanza menaruh `logo` dan
 * `wallpaper` sebagai longblob di baris yang sama dengan alamat dan kontak,
 * sehingga setiap pembacaan nama rumah sakit — yang terjadi di hampir
 * setiap cetakan — ikut menarik dua gumpalan biner.
 *
 * TIDAK ADA KREDENSIAL DI SINI. Sandi sistem luar sudah punya rumahnya
 * sendiri di `integration.integration_credentials`; menaruhnya di tabel
 * pengaturan umum berarti siapa pun yang boleh mengubah jam makan pasien
 * juga bisa membaca sandi BPJS.
 */
return new class extends Migration
{
    private const S = 'platform';

    private const TIPE = ['teks', 'angka', 'uang', 'boolean', 'waktu', 'tanggal', 'pilihan'];

    /**
     * Kelompok pengaturan — satu per layar, bukan satu per kode Khanza.
     */
    private const KELOMPOK = ['umum', 'billing', 'farmasi', 'ranap', 'antrian', 'presensi'];

    public function up(): void
    {
        Schema::create(self::S.'.settings', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * Kuncinya berbentuk `kelompok:nama`, memakai titik dua — BUKAN
             * titik. Titik akan membuat `farmasi.embalase_per_obat` terbaca
             * persis seperti rujukan `schema.tabel`, yang di seluruh sistem
             * ini berarti "menyentuh schema konteks lain". Uji batas konteks
             * memang menangkapnya, tapi masalahnya bukan cuma regex:
             * pembaca manusia pun tidak bisa membedakan keduanya, dan
             * pengaturan bernama seperti tabel akan dicari orang di tempat
             * yang salah.
             */
            $table->string('key', 80)->unique();
            $table->string('group', 20);
            $table->string('label', 150);
            $table->text('description')->nullable();

            $table->string('value_type', 12);

            /*
             * Nilai berjalan disimpan di sini sebagai teks apa pun tipenya:
             * satu kolom yang tipenya ditentukan `value_type` lebih jujur
             * daripada enam kolom yang lima di antaranya selalu kosong.
             * Pembacaannya lewat SettingStore, yang mengembalikan tipe yang
             * benar — bukan lewat kolom yang ditebak pemanggil.
             *
             * BOLEH KOSONG, dan itu bukan sama dengan nol maupun "tidak".
             * Pengaturan yang belum pernah ditetapkan RSP UI harus bisa
             * dibedakan dari yang sengaja ditetapkan nol — kalau tidak,
             * embalase yang belum diputuskan akan terbaca "gratis" dan
             * tertagih diam-diam sebagai nol.
             */
            $table->text('value')->nullable();

            // Untuk value_type 'pilihan': daftar nilai yang sah.
            $table->jsonb('options')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['group', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.".settings ADD CONSTRAINT settings_type_check
            CHECK (value_type IN ('".implode("','", self::TIPE)."'))");

        DB::statement('ALTER TABLE '.self::S.".settings ADD CONSTRAINT settings_group_check
            CHECK (\"group\" IN ('".implode("','", self::KELOMPOK)."'))");

        /*
         * RIWAYAT, YANG SAMA SEKALI TIDAK ADA DI KHANZA.
         *
         * Setiap perubahan pengaturan menulis satu baris di sini berikut
         * nilai sebelumnya, siapa yang mengubah, dan sejak kapan berlaku.
         * Tanpa ini, pertanyaan "tarif embalase berapa saat resep ini
         * dilayani" hanya bisa dijawab dengan nilai HARI INI — dan menagih
         * ulang bulan lalu dengan tarif hari ini adalah kesalahan yang
         * tidak akan pernah terlihat oleh siapa pun yang memeriksa.
         */
        Schema::create(self::S.'.setting_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('setting_id')->constrained(self::S.'.settings')->cascadeOnDelete();

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->date('effective_from');

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_name', 150)->nullable();

            // Perubahan pengaturan yang menyentuh uang wajib beralasan:
            // yang membacanya belakangan adalah orang yang sedang mencari
            // sebab selisih tagihan, dan "diubah" tanpa "kenapa" tidak
            // menutup pemeriksaan apa pun.
            $table->text('reason')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['setting_id', 'effective_from']);
        });

        // ------------------------------------------- identitas institusi

        Schema::create(self::S.'.institution', function (Blueprint $table) {
            /*
             * Kunci tunggal yang dipaksa CHECK, bukan nama rumah sakitnya.
             * Nama adalah atribut yang boleh berubah; identitas tidak.
             */
            $table->unsignedSmallInteger('id')->primary();

            $table->string('name', 150);
            $table->string('address', 250)->nullable();
            $table->string('city', 60)->nullable();
            $table->string('province', 60)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 100)->nullable();

            // Kode fasilitas kesehatan pada tiap sistem luar. Kodenya
            // berbeda per sistem dan itu memang kenyataannya — satu kolom
            // "kode ppk" akan memaksa salah satunya salah.
            $table->string('code_kemenkes', 20)->nullable();
            $table->string('code_bpjs', 20)->nullable();
            $table->string('code_inhealth', 20)->nullable();

            // Rujukan berkas, bukan gumpalan biner di baris yang dibaca
            // hampir setiap cetakan.
            $table->string('logo_path', 250)->nullable();

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE '.self::S.'.institution
            ADD CONSTRAINT institution_singleton_check CHECK (id = 1)');

        $this->seedSettings();
    }

    /**
     * Pengaturan yang PERTANYAANNYA sudah pasti, tapi JAWABANNYA belum.
     *
     * Yang diseed hanya kuncinya — nilainya sengaja NULL. Besaran embalase,
     * jam makan pasien, dan batas jam kamar inap adalah keputusan RSP UI;
     * menebaknya berarti sistem mulai menagih dan menjadwalkan dengan angka
     * yang tidak pernah diputuskan siapa pun. Yang membedakan ini dari
     * diam: kuncinya ADA, jadi layar pengaturan menampilkannya sebagai
     * pekerjaan yang belum selesai, bukan menyembunyikannya.
     */
    private function seedSettings(): void
    {
        $waktu = ['created_at' => now(), 'updated_at' => now()];

        $daftar = [
            // aplikasi + admin
            ['app:name', 'umum', 'teks', 'Nama aplikasi pada layar & cetakan', null],
            ['app:timezone', 'umum', 'teks', 'Zona waktu operasional', 'Asia/Jakarta'],

            // setup_embalase — embalase & tuslah per obat
            ['farmasi:embalase_per_obat', 'farmasi', 'uang',
                'Embalase per baris obat pada resep', null],
            ['farmasi:tuslah_per_obat', 'farmasi', 'uang',
                'Tuslah (jasa racik/pelayanan) per baris obat', null],

            // set_harga_obat — dasar penetapan harga jual obat
            ['farmasi:harga_dasar', 'farmasi', 'pilihan',
                'Harga jual obat dihitung dari harga beli atau harga diskon', null],
            ['farmasi:ppn_ditagihkan', 'farmasi', 'boolean',
                'PPN obat ditagihkan terpisah kepada pasien', null],

            // setup_jam_kamin — batas jam perhitungan kamar inap
            ['ranap:jam_batas_kamar', 'ranap', 'waktu',
                'Jam batas perhitungan hari rawat', null],

            // set_penggunaan_tarif
            ['billing:sumber_tarif', 'billing', 'pilihan',
                'Tarif yang dipakai saat penjamin punya tarif sendiri', null],

            // set_oto_ralan
            ['billing:oto_biaya_ralan', 'billing', 'boolean',
                'Biaya registrasi rawat jalan dibebankan otomatis saat mendaftar', null],

            // set_input_parsial
            ['billing:input_parsial', 'billing', 'boolean',
                'Tindakan boleh ditagihkan sebelum kunjungan ditutup', null],

            // set_nota + set_no_rm — format penomoran
            ['billing:format_nota', 'billing', 'teks', 'Format nomor nota tagihan', null],
            ['umum:format_no_rm', 'umum', 'teks', 'Format nomor rekam medis', null],
        ];

        foreach ($daftar as [$kunci, $kelompok, $tipe, $label, $nilai]) {
            DB::table(self::S.'.settings')->insert([
                'key' => $kunci,
                'group' => $kelompok,
                'label' => $label,
                'value_type' => $tipe,
                'value' => $nilai,
                'is_active' => true,
            ] + $waktu);
        }

        DB::table(self::S.'.settings')
            ->where('key', 'farmasi:harga_dasar')
            ->update(['options' => json_encode(['harga-beli', 'harga-diskon'])]);

        DB::table(self::S.'.settings')
            ->where('key', 'billing:sumber_tarif')
            ->update(['options' => json_encode(['tarif-penjamin', 'tarif-rumah-sakit'])]);
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.institution');
        Schema::dropIfExists(self::S.'.setting_revisions');
        Schema::dropIfExists(self::S.'.settings');
    }
};
