<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resep apotek BPJS & resep iterasi (domain L item M).
 *
 * Menaungi bpjs_daftar_resep_apotek, daftar_permintaan_resep_iterasi_bpjs,
 * dan bpjs_kunjungan_sep_apotek (yang terakhir memakai tabel pencarian
 * peserta dari item J — jenis pencarian baru, bukan tabel baru).
 *
 * bpjs_monitoring_klaim_apotek TIDAK dibangun di sini: monitoring klaim
 * dengan lingkup 'apotek' sudah ada sejak item C, dan menambah jalur kedua
 * berarti dua angka klaim apotek yang bisa berbeda.
 *
 * BEDANYA DENGAN PELAYANAN OBAT DI ITEM G: yang di sana adalah PELAYANAN
 * (obat sudah diserahkan, dilaporkan untuk ditagihkan); yang di sini adalah
 * RESEPNYA — apa yang ditulis dokter dan dikirim ke Apotek Online BPJS,
 * termasuk resep yang belum, atau tidak pernah, ditebus.
 *
 * RESEP ITERASI ADALAH SATU RESEP YANG DITEBUS BERKALI-KALI, BUKAN RESEP
 * BARU TIAP BULAN. Peserta PRB boleh menebus resep yang sama sampai tiga
 * kali tanpa kembali ke dokter. Mencatat tiap penebusan sebagai resep baru
 * akan (a) mengklaim tiga resep padahal dokter menulis satu, dan (b)
 * membuat jatah iterasi bisa terlampaui tanpa ada yang terlihat salah —
 * karena tidak ada satu pun baris yang tahu ini penebusan ke berapa.
 * Karena itu penebusan lanjutan MENUNJUK resep induknya, dan nomor
 * urutannya unik per induk.
 */
return new class extends Migration
{
    private const S = 'integration';

    /** Batas iterasi resep PRB menurut ketentuan BPJS. */
    private const MAKS_ITERASI = 3;

    public function up(): void
    {
        Schema::create(self::S . '.bpjs_apotek_prescriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Resep kita di konteks pharmacy. Tanpa foreign key lintas
            // schema — batas konteks ditegakkan lewat kontrak view, bukan FK.
            $table->unsignedBigInteger('prescription_id')->nullable();
            $table->string('prescription_number', 40)->nullable();

            $table->string('sep_number', 30);
            $table->string('card_number', 20);
            $table->string('patient_name', 150)->nullable();

            $table->string('apotek_code', 40)->nullable()->comment('Kode PPK apotek pelayan');
            $table->string('prescriber_name', 150)->nullable();
            $table->date('prescribed_on');

            // Nomor resep menurut BPJS — dari mereka, bukan dinomori sendiri.
            $table->string('bpjs_prescription_number', 40)->nullable();

            // Iterasi: resep induk boleh ditebus beberapa kali.
            $table->boolean('is_iterative')->default(false);
            $table->unsignedSmallInteger('iteration_allowed')->default(0)
                ->comment('Jatah penebusan lanjutan; BPJS membatasi ' . self::MAKS_ITERASI);
            $table->unsignedSmallInteger('iteration_index')->default(0)
                ->comment('0 = resep induk, 1..n = penebusan lanjutan');
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->json('items')->nullable()->comment('Rincian obat, dibekukan saat dikirim');

            $table->string('status', 20)->default('terkirim');
            $table->string('response_code', 10)->nullable();
            $table->string('response_message', 300)->nullable();
            $table->json('raw_response')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestampsTz();

            $table->foreign('parent_id')->references('id')->on(self::S . '.bpjs_apotek_prescriptions');

            $table->index(['prescribed_on', 'status']);
            $table->index('sep_number');
            $table->index('card_number');
            $table->index('parent_id');
        });

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_apotek_prescriptions
            ADD CONSTRAINT bpjs_apotek_prescriptions_status_check
            CHECK (status IN ('terkirim','gagal','batal'))");

        // Jatah iterasi tidak boleh melebihi ketentuan BPJS, dan penebusan
        // ke-n tidak boleh melebihi jatahnya sendiri.
        DB::statement('ALTER TABLE ' . self::S . ".bpjs_apotek_prescriptions
            ADD CONSTRAINT bpjs_apotek_prescriptions_iterasi_check
            CHECK (iteration_allowed <= " . self::MAKS_ITERASI . '
                   AND iteration_index <= iteration_allowed)');

        // Resep induk tidak menunjuk siapa pun; penebusan lanjutan wajib
        // menunjuk induknya. Tanpa ini, penebusan bisa berdiri sendiri dan
        // jatahnya tidak terlacak.
        DB::statement('ALTER TABLE ' . self::S . ".bpjs_apotek_prescriptions
            ADD CONSTRAINT bpjs_apotek_prescriptions_induk_check
            CHECK ((iteration_index = 0 AND parent_id IS NULL)
                   OR (iteration_index > 0 AND parent_id IS NOT NULL))");

        // Satu penebusan per urutan per induk — dua penebusan ke-2 atas
        // resep yang sama berarti obat ganda yang diklaim dua kali.
        DB::statement('CREATE UNIQUE INDEX bpjs_apotek_iterasi_unique
            ON ' . self::S . ".bpjs_apotek_prescriptions (parent_id, iteration_index)
            WHERE parent_id IS NOT NULL AND status <> 'batal'");

        // Pencarian SEP dari sisi apotek adalah jenis pencarian baru pada
        // tabel item J, bukan tabel tersendiri: perbuatannya sama persis —
        // bertanya kepada BPJS dan menyimpan jawabannya.
        $lama = DB::selectOne("SELECT conname FROM pg_constraint
            WHERE conrelid = 'integration.bpjs_member_lookups'::regclass
              AND contype = 'c' AND conname LIKE '%type_check%'");

        if ($lama !== null) {
            DB::statement('ALTER TABLE ' . self::S . ".bpjs_member_lookups DROP CONSTRAINT {$lama->conname}");
        }

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_member_lookups
            ADD CONSTRAINT bpjs_member_lookups_type_check
            CHECK (lookup_type IN ('nik','skdp','histori','fingerprint','sep-apotek'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.bpjs_apotek_prescriptions');

        $lama = DB::selectOne("SELECT conname FROM pg_constraint
            WHERE conrelid = 'integration.bpjs_member_lookups'::regclass
              AND contype = 'c' AND conname LIKE '%type_check%'");

        if ($lama !== null) {
            DB::statement('ALTER TABLE ' . self::S . ".bpjs_member_lookups DROP CONSTRAINT {$lama->conname}");
        }

        DB::statement('ALTER TABLE ' . self::S . ".bpjs_member_lookups
            ADD CONSTRAINT bpjs_member_lookups_type_check
            CHECK (lookup_type IN ('nik','skdp','histori','fingerprint'))");
    }
};
