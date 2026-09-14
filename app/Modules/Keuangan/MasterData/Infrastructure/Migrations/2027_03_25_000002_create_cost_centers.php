<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klasifikasi pusat biaya & pusat pendapatan — Modul A butir 1.10.
 *
 * MENGAPA TABEL TERSENDIRI, BUKAN KOLOM PADA organization.units.
 *
 * Unit organisasi milik seluruh sistem: pendaftaran memakainya untuk poli,
 * farmasi untuk depo, kepegawaian untuk penempatan. Menambahkan kolom
 * akuntansi ke sana berarti keuangan ikut memiliki tabel yang bukan
 * miliknya — dan uji batas konteks menolaknya, dengan benar.
 *
 * Yang lebih menentukan: klasifikasi ini BERUBAH sementara unitnya tidak.
 * Sebuah poli bisa berpindah dari pusat biaya jadi pusat pendapatan saat
 * layanannya mulai ditagihkan, dan laporan tahun lalu harus tetap memakai
 * klasifikasi yang berlaku waktu itu. Kolom pada unit akan tertimpa;
 * tabel berperiode tidak.
 *
 * EMPAT JENIS, DAN PEMBEDAANNYA MENENTUKAN CARA ALOKASI BIAYA:
 *
 *   revenue-center   — menghasilkan pendapatan langsung (poli, ranap,
 *                      lab, radiologi, farmasi). Penerima alokasi.
 *
 *   cost-center      — tidak menghasilkan pendapatan, melayani unit lain
 *                      (laundry, gizi, IPSRS, CSSD). Biayanya DIALOKASIKAN
 *                      ke revenue center lewat cost driver — inilah dasar
 *                      Activity-Based Costing pada Wave 7.
 *
 *   support-center   — manajemen dan administrasi (direksi, keuangan, SDM).
 *                      Dialokasikan juga, tapi cost driver-nya berbeda
 *                      sifatnya: bukan volume layanan melainkan ukuran
 *                      organisasi.
 *
 *   program-center   — pendidikan dan penelitian. SENGAJA DIPISAH untuk
 *                      RSP UI yang berstatus PTN-BH: dana pendidikan dan
 *                      penelitian harus bisa dipertanggungjawabkan
 *                      terpisah, dan menggabungkannya dengan support
 *                      center membuat biaya pendidikan tersebar ke tarif
 *                      pelayanan — yang berarti pasien ikut membiayai
 *                      pendidikan tanpa ada yang memutuskannya.
 *
 * SATU UNIT BISA PUNYA BEBERAPA PUSAT BIAYA. Instalasi radiologi dengan
 * dua ruang berbeda bisa dipisah supaya biayanya tidak bercampur. Karena
 * itu unit_id TIDAK unik.
 */
return new class extends Migration
{
    private const S = 'keuangan_master';

    private const JENIS = ['revenue-center', 'cost-center', 'support-center', 'program-center'];

    /** Dasar alokasi biaya ke unit penerima — dipakai ABC pada Wave 7. */
    private const COST_DRIVER = [
        'luas-lantai', 'jumlah-pegawai', 'jumlah-kunjungan', 'jumlah-hari-rawat',
        'jam-mesin', 'jumlah-porsi', 'berat-cucian', 'jumlah-permintaan', 'manual',
    ];

    public function up(): void
    {
        Schema::create(self::S.'.cost_centers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('jenis', 20);

            $table->unsignedBigInteger('unit_id')->nullable()
                ->comment('organization.units, referensi longgar. Null untuk pusat biaya '
                    .'yang tidak berpadanan satu unit — mis. "Manajemen Umum"');

            $table->unsignedBigInteger('parent_id')->nullable()
                ->comment('Hierarki, supaya induk menjumlahkan anaknya');

            /*
             * Cost driver hanya bermakna untuk pusat yang biayanya
             * DIALOKASIKAN. Revenue center adalah penerima, bukan pemberi
             * — memberinya cost driver berarti menyiratkan biayanya akan
             * dialokasikan lagi ke tempat lain, dan itu alokasi berputar.
             */
            $table->string('cost_driver', 30)->nullable();

            $table->string('default_program', 20)->nullable()
                ->comment('pelayanan/pendidikan/penelitian — dimensi PTN-BH');

            $table->boolean('is_active')->default(true);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->timestampsTz();

            $table->foreign('parent_id')->references('id')->on(self::S.'.cost_centers');
            $table->index(['jenis', 'is_active']);
            $table->index('unit_id');
        });

        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_jenis_check
            CHECK (jenis IN (\''.implode("','", self::JENIS).'\'))');

        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_driver_check
            CHECK (cost_driver IS NULL OR cost_driver IN (\''.implode("','", self::COST_DRIVER).'\'))');

        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_program_check
            CHECK (default_program IS NULL OR default_program IN (\'pelayanan\',\'pendidikan\',\'penelitian\'))');

        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_period_check
            CHECK (valid_until IS NULL OR valid_until >= valid_from)');

        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_parent_bukan_diri
            CHECK (parent_id IS NULL OR parent_id <> id)');

        /*
         * REVENUE CENTER TIDAK BOLEH PUNYA COST DRIVER. Ia penerima
         * alokasi, bukan pemberi; memberinya cost driver menyiratkan
         * biayanya akan dialokasikan lagi ke tempat lain — dan alokasi
         * yang berputar tidak pernah selesai dihitung.
         */
        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_revenue_tanpa_driver
            CHECK (jenis <> \'revenue-center\' OR cost_driver IS NULL)');

        /*
         * PUSAT YANG DIALOKASIKAN WAJIB PUNYA COST DRIVER saat aktif.
         * Tanpa itu, biayanya tidak punya dasar pembagian dan akan
         * tertinggal di pusat biayanya sendiri — laporan unit cost lalu
         * menunjukkan layanan yang jauh lebih murah daripada kenyataannya,
         * karena biaya laundry dan gizi tidak pernah sampai ke sana.
         */
        DB::statement('ALTER TABLE '.self::S.'.cost_centers ADD CONSTRAINT cost_centers_dialokasikan_perlu_driver
            CHECK (is_active = false
                   OR jenis = \'revenue-center\'
                   OR cost_driver IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S.'.cost_centers');
    }
};
