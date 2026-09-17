<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan jabatan, unit kerja, dan status kepegawaian pada praktisi.
 *
 * MENGAPA UNIT KERJA DISIMPAN SEBAGAI TEKS, bukan hanya sebagai tautan ke
 * organization.units. Keduanya menjawab pertanyaan yang berbeda:
 *
 *   practitioner_units — unit LAYANAN tempat seseorang melayani pasien.
 *                        Satu dokter bisa membuka praktik di beberapa
 *                        poliklinik, dan itulah yang dipakai registrasi.
 *
 *   unit_name          — unit KERJA menurut struktur kepegawaian: "Sub
 *                        Direktorat Keperawatan", "Sub Instalasi Binatu dan
 *                        CSSD", "KSM Umum". Sebagian besar dari 69 unit ini
 *                        bukan tempat pasien dilayani sama sekali, dan
 *                        memaksakannya menjadi baris organization.units —
 *                        yang kind-nya cuma menerima poliklinik/igd/
 *                        rawat-inap/penunjang — berarti menerbitkan
 *                        "Sub Direktorat Perencanaan" sebagai unit layanan
 *                        yang bisa dipilih saat mendaftarkan pasien.
 *
 * Unit kerja yang MEMANG unit layanan tetap ditautkan lewat
 * practitioner_units; kolom ini menyimpan penempatan kepegawaiannya apa
 * adanya, seperti tertulis di daftar SDM.
 *
 * employment_status bukan staff_kind. staff_kind menjawab "boleh jadi DPJP
 * atau tidak" (pegawai/residen/mahasiswa); kolom ini menyimpan status
 * kepegawaian sebagaimana ditulis SDM — "Pegawai Tetap", "Spesialis Mitra",
 * "Dokter Umum Tetap" — yang bentuknya ditentukan RSUI dan bisa bertambah.
 */
return new class extends Migration
{
    private const S = 'organization';

    public function up(): void
    {
        Schema::table(self::S.'.practitioners', function (Blueprint $table) {
            $table->string('position', 120)->nullable()->after('support_type');
            $table->string('unit_name', 150)->nullable()->after('position');
            $table->string('employment_status', 60)->nullable()->after('unit_name');
            $table->string('entry_status', 30)->nullable()->after('employment_status');

            $table->index('unit_name');
            $table->index('position');
        });

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.position IS
            'Jabatan menurut daftar kepegawaian, mis. Ners, Radiografer, Staf Sterilisasi (CSSD).'");

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.unit_name IS
            'Unit KERJA kepegawaian (Sub Direktorat/Instalasi/KSM) — berbeda dari
             practitioner_units yang menyatakan unit LAYANAN tempat melayani pasien.'");

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.employment_status IS
            'Status kepegawaian apa adanya dari SDM: Pegawai Tetap, Spesialis Mitra, dst.'");

        DB::statement("COMMENT ON COLUMN ".self::S.".practitioners.entry_status IS
            'Jalur masuk: PRSUI, PNS, PPPK, PUI, CPUI.'");

        /*
         * Kontrak baca ikut diperbarui — unit kerja dan jabatan justru yang
         * paling sering ditanyakan konteks lain saat menampilkan siapa yang
         * mencatat sesuatu.
         */
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_practitioner_summary');

        DB::statement("CREATE VIEW ".self::S.".v_practitioner_summary AS
            SELECT id, code, employee_number, name, title, specialty, is_active,
                   active_from, active_until, category, staff_kind, support_type,
                   position, unit_name, employment_status
            FROM ".self::S.".practitioners
            WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_practitioner_summary');

        Schema::table(self::S.'.practitioners', function (Blueprint $table) {
            $table->dropIndex(['unit_name']);
            $table->dropIndex(['position']);
            $table->dropColumn(['position', 'unit_name', 'employment_status', 'entry_status']);
        });

        DB::statement("CREATE VIEW ".self::S.".v_practitioner_summary AS
            SELECT id, code, employee_number, name, title, specialty, is_active,
                   active_from, active_until, category, staff_kind, support_type
            FROM ".self::S.".practitioners
            WHERE deleted_at IS NULL");
    }
};
