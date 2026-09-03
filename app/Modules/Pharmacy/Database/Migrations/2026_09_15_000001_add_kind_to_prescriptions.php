<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * resep_pulang (Khanza domain A, kelas DlgResepPulang) — tercatat context=
 * encounter di katalog, tapi paket Java-nya "inventory" (lihat
 * Khanza_Functional_Dependency_Map.xlsx) — memicu potong stok obat persis
 * seperti resep_obat biasa, jadi dibangun di pharmacy, bukan encounter.
 *
 * Bukan tabel baru — cuma kolom 'kind' pada pharmacy.prescriptions
 * (rawat-jalan vs pulang) supaya kedua kode Khanza memakai alur telaah-
 * serahkan yang sama persis (ditulis->menunggu-telaah->disetujui->
 * diserahkan). Bedanya cuma KAPAN resep ditulis (rawat_jalan: selama
 * kunjungan berjalan; pulang: menjelang pasien pulang, biasanya dari
 * ranap/IGD) — bukan proses yang berbeda. Satu registrasi boleh punya
 * resep rawat-jalan DAN resep pulang terpisah (mis. pasien ranap dapat
 * obat harian selama dirawat, lalu resep pulang terpisah saat discharge) —
 * makanya index unik prescriptions_single_open (satu resep berjalan per
 * kunjungan) diperlebar jadi per kunjungan PER JENIS, bukan dihapus.
 */
return new class extends Migration
{
    private const S = 'pharmacy';

    public function up(): void
    {
        Schema::table(self::S . '.prescriptions', function (Blueprint $table) {
            $table->string('kind', 20)->default('rawat-jalan')->after('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".prescriptions ADD CONSTRAINT prescriptions_kind_check
            CHECK (kind IN ('rawat-jalan','pulang'))");

        DB::statement('DROP INDEX ' . self::S . '.prescriptions_single_open');
        DB::statement("CREATE UNIQUE INDEX prescriptions_single_open
            ON " . self::S . ".prescriptions (registration_id, kind)
            WHERE status IN ('ditulis','menunggu-telaah') AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX ' . self::S . '.prescriptions_single_open');
        DB::statement("CREATE UNIQUE INDEX prescriptions_single_open
            ON " . self::S . ".prescriptions (registration_id)
            WHERE status IN ('ditulis','menunggu-telaah') AND deleted_at IS NULL");

        Schema::table(self::S . '.prescriptions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
