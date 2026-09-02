<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks organization: unit layanan dan praktisi.
 *
 * Praktisi di sini adalah identitas profesional — siapa yang boleh jadi DPJP,
 * SIP-nya apa, aktif sampai kapan. Orang/pegawainya nanti milik konteks hr.
 * Khanza mencampur keduanya di satu tabel `dokter`, dan itu yang bikin kd_dokter
 * tersebar ke mana-mana tanpa batas yang jelas.
 */
return new class extends Migration
{
    private const S = 'organization';

    public function up(): void
    {
        Schema::create(self::S . '.units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('kind', 20)->index()->comment('poliklinik, igd, rawat-inap, penunjang, penunjang-medis');

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true)->index();

            // Kuota antrean harian. Null berarti tanpa batas.
            $table->unsignedSmallInteger('daily_quota')->nullable();

            $table->timestampsTz();

            $table->foreign('parent_id')->references('id')->on(self::S . '.units')->nullOnDelete();
        });

        DB::statement("ALTER TABLE " . self::S . ".units ADD CONSTRAINT units_kind_check
            CHECK (kind IN ('poliklinik','igd','rawat-inap','penunjang','penunjang-medis'))");

        Schema::create(self::S . '.practitioners', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique()->comment('Setara kd_dokter Khanza');

            // Jembatan ke konteks hr. Sengaja tanpa foreign key: beda schema.
            $table->string('employee_number', 30)->nullable()->index();

            $table->string('name', 150);
            $table->string('title', 60)->nullable()->comment('Gelar, mis. dr., Sp.PD');
            $table->string('specialty', 100)->nullable()->index();

            $table->string('sip_number', 120)->nullable()->comment('Nomor Surat Izin Praktik');
            $table->date('sip_valid_until')->nullable();

            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();

            /*
             * Masa aktif. Diambil dari kebutuhan nyata Khawarizmi: MasterController
             * menonaktifkan dokter otomatis begitu tanggal non-aktifnya lewat.
             * Kolom ini tidak ada di sik_schema.sql, jadi memang harus dibuat.
             */
            $table->boolean('is_active')->default(true);
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['is_active', 'name']);
            $table->index('active_until');
        });

        // Praktisi mana melayani unit mana. Satu dokter bisa di beberapa poli.
        Schema::create(self::S . '.practitioner_units', function (Blueprint $table) {
            $table->foreignId('practitioner_id')->constrained(self::S . '.practitioners')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained(self::S . '.units')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->primary(['practitioner_id', 'unit_id']);
            $table->index('unit_id');
        });

        /*
         * Kontrak baca untuk konteks lain. encounter perlu menampilkan nama poli
         * dan nama dokter di layar daftar tanpa menyentuh tabel di atas langsung.
         */
        DB::statement('CREATE VIEW ' . self::S . '.v_unit_summary AS
            SELECT id, code, name, kind, is_active, daily_quota
            FROM ' . self::S . '.units');

        DB::statement("CREATE VIEW " . self::S . ".v_practitioner_summary AS
            SELECT id, code, employee_number, name, title, specialty, is_active,
                   active_from, active_until
            FROM " . self::S . ".practitioners
            WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_practitioner_summary');
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_unit_summary');

        Schema::dropIfExists(self::S . '.practitioner_units');
        Schema::dropIfExists(self::S . '.practitioners');
        Schema::dropIfExists(self::S . '.units');
    }
};
