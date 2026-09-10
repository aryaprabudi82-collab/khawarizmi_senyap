<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanggung jawab unit penunjang (Khanza `setup_pjlab`, domain U).
 *
 * PENUGASAN ADALAH PERISTIWA BERJANGKA WAKTU, BUKAN SATU BARIS BERKOLOM.
 * `set_pjlab` Khanza menaruh ENAM dokter penanggung jawab dalam SATU baris
 * — lab, radiologi, hemodialisa, UTD, patologi anatomi, mikrobiologi —
 * dengan PRIMARY KEY gabungan dari tiga di antaranya. Dua akibatnya:
 *
 * 1. Menukar penanggung jawab lab berarti mengubah sebagian kunci baris,
 *    yaitu membuat baris yang berbeda. Yang lama tidak berpindah; ia
 *    tinggal, atau tertimpa, tergantung urutan tulis.
 *
 * 2. Tidak ada riwayat sama sekali. Pertanyaan "siapa penanggung jawab
 *    laboratorium bulan Maret" — yang justru ditanyakan saat ada hasil
 *    dipersoalkan, saat akreditasi memeriksa, atau saat insiden ditelusuri
 *    — tidak punya jawaban. Yang tersimpan cuma siapa yang menjabat HARI
 *    INI, dan itu bukan yang ditanyakan.
 *
 * MENAMBAH UNIT PENUNJANG KETUJUH DI KHANZA BERARTI MENAMBAH KOLOM. Di
 * sini ia satu baris, karena unit penunjang memang bertambah: rumah sakit
 * membuka layanan baru, dan struktur data tidak boleh jadi alasan menunda.
 *
 * SATU PENANGGUNG JAWAB PER UNIT PADA SATU WAKTU, ditegakkan indeks unik
 * parsial atas penugasan yang masih terbuka. Dua penanggung jawab
 * bersamaan bukan kelonggaran administratif — ia berarti tidak ada yang
 * tahu tanda tangan siapa yang sah pada hasil pemeriksaan.
 */
return new class extends Migration
{
    private const S = 'organization';

    public function up(): void
    {
        Schema::create(self::S.'.unit_supervisors', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('practitioner_id');

            $table->date('start_date');

            /*
             * Kosong berarti MASIH MENJABAT — bukan "tidak diketahui".
             * Menutup penugasan adalah tindakan tersendiri yang punya
             * tanggalnya sendiri, dan menuntut tanggal akhir sejak awal
             * akan memaksa orang mengarang tanggal berhenti bagi orang
             * yang belum berhenti.
             */
            $table->date('end_date')->nullable();

            $table->string('decree_number', 60)->nullable()->comment('Nomor SK penetapan');
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['unit_id', 'start_date']);
        });

        foreach (['unit_id' => 'units', 'practitioner_id' => 'practitioners'] as $kolom => $tujuan) {
            DB::statement('ALTER TABLE '.self::S.'.unit_supervisors
                ADD CONSTRAINT unit_supervisors_'.$kolom.'_fk
                FOREIGN KEY ('.$kolom.') REFERENCES '.self::S.'.'.$tujuan.' (id)');
        }

        DB::statement('ALTER TABLE '.self::S.'.unit_supervisors
            ADD CONSTRAINT unit_supervisors_period_check
            CHECK (end_date IS NULL OR end_date >= start_date)');

        // Satu penanggung jawab yang masih menjabat per unit.
        DB::statement('CREATE UNIQUE INDEX unit_supervisors_terbuka_unique
            ON '.self::S.'.unit_supervisors (unit_id) WHERE end_date IS NULL');

        /*
         * Diterbitkan supaya konteks lain — order (hasil lab/radiologi),
         * blood (UTD), envlab — bisa menanyakan siapa penanggung jawab
         * sebuah unit tanpa mengimpor model konteks ini.
         *
         * Menyertakan tanggalnya, bukan hanya yang menjabat sekarang:
         * pembaca yang mencetak ulang hasil pemeriksaan lama harus bisa
         * menemukan penanggung jawab pada tanggal pemeriksaan itu, bukan
         * penanggung jawab hari ini.
         */
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_unit_supervisor AS
            SELECT s.id,
                   s.unit_id,
                   u.code  AS unit_code,
                   u.name  AS unit_name,
                   s.practitioner_id,
                   p.name  AS practitioner_name,
                   s.start_date,
                   s.end_date,
                   s.decree_number,
                   (s.end_date IS NULL) AS is_current
              FROM '.self::S.'.unit_supervisors s
              JOIN '.self::S.'.units u ON u.id = s.unit_id
              JOIN '.self::S.'.practitioners p ON p.id = s.practitioner_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_unit_supervisor');
        Schema::dropIfExists(self::S.'.unit_supervisors');
    }
};
