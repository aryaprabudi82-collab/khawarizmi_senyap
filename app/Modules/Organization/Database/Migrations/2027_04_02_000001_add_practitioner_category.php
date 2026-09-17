<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan penggolongan praktisi: kategori profesi dan jenis ketenagaan.
 *
 * MENGAPA DUA KOLOM, BUKAN SATU. Keduanya menjawab pertanyaan yang berbeda,
 * dan menggabungkannya membuat salah satunya tidak terjawab:
 *
 *   category  — "profesinya apa": dokter, perawat, penunjang medis, non-medis.
 *               Ini yang dipakai memilah daftar dan menyusun laporan
 *               ketenagaan.
 *
 *   staff_kind — "statusnya apa": pegawai, residen (PPDS), atau mahasiswa.
 *               Ini yang menentukan SIAPA BOLEH JADI DPJP.
 *
 * TANPA staff_kind, SISTEM INI BERBAHAYA. Ekspor tenaga kesehatan RSUI
 * memuat 6.825 orang, dan 4.484 di antaranya (66%) adalah mahasiswa
 * kedokteran, mahasiswa keperawatan, mahasiswa FKG, peserta PKPA farmasi,
 * dan dokter residen. RSUI rumah sakit pendidikan — mereka memang terdaftar
 * dan memang hadir di ruangan. Tapi mahasiswa kedokteran bukan dokter
 * penanggung jawab pelayanan, dan daftar pilihan DPJP yang memuat 1.346
 * mahasiswa cepat atau lambat akan terpilih salah satu.
 *
 * Yang boleh jadi DPJP hanya yang staff_kind-nya 'pegawai' — 994 dokter dari
 * 6.825 baris.
 *
 * KEDUANYA NULLABLE. 6 praktisi contoh yang sudah ada tidak punya nilai ini,
 * dan memaksa NOT NULL berarti menebakkan kategori untuk baris yang tidak
 * berasal dari sumber mana pun.
 */
return new class extends Migration
{
    private const S = 'organization';

    public function up(): void
    {
        Schema::table(self::S.'.practitioners', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('specialty');
            $table->string('staff_kind', 20)->nullable()->after('category');
            $table->string('support_type', 30)->nullable()->after('staff_kind');

            $table->index(['category', 'staff_kind']);
        });

        DB::statement("ALTER TABLE ".self::S.".practitioners ADD CONSTRAINT practitioners_category_check
            CHECK (category IS NULL OR category IN ('dokter','perawat','penunjang','non-medis'))");

        DB::statement("ALTER TABLE ".self::S.".practitioners ADD CONSTRAINT practitioners_staff_kind_check
            CHECK (staff_kind IS NULL OR staff_kind IN ('pegawai','residen','mahasiswa'))");

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.category IS
            'Golongan profesi: dokter, perawat, penunjang (farmasi/rehab/lab/gizi/dll), non-medis.'");

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.staff_kind IS
            'Status ketenagaan. HANYA pegawai yang boleh jadi DPJP — residen dan
             mahasiswa hadir di ruangan tapi bukan penanggung jawab pelayanan.'");

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.support_type IS
            'Untuk category=penunjang: farmasi, rehab-medik, laboratorium, gizi,
             psikologi, dan seterusnya. NULL untuk kategori lain.'");

        /*
         * Kontrak baca diperbarui. v_practitioner_summary dibaca encounter untuk
         * daftar pilihan dokter — dan justru di sanalah pembedaan ini paling
         * dibutuhkan. Kolomnya ditambahkan di belakang supaya konsumen yang
         * sudah ada tidak berubah urutan kolomnya.
         */
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_practitioner_summary');

        DB::statement("CREATE VIEW ".self::S.".v_practitioner_summary AS
            SELECT id, code, employee_number, name, title, specialty, is_active,
                   active_from, active_until, category, staff_kind, support_type
            FROM ".self::S.".practitioners
            WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_practitioner_summary');

        DB::statement('ALTER TABLE '.self::S.'.practitioners DROP CONSTRAINT IF EXISTS practitioners_staff_kind_check');
        DB::statement('ALTER TABLE '.self::S.'.practitioners DROP CONSTRAINT IF EXISTS practitioners_category_check');

        Schema::table(self::S.'.practitioners', function (Blueprint $table) {
            $table->dropIndex(['category', 'staff_kind']);
            $table->dropColumn(['category', 'staff_kind', 'support_type']);
        });

        DB::statement("CREATE VIEW ".self::S.".v_practitioner_summary AS
            SELECT id, code, employee_number, name, title, specialty, is_active,
                   active_from, active_until
            FROM ".self::S.".practitioners
            WHERE deleted_at IS NULL");
    }
};
