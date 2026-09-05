<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memperbaiki batasan yang diakui saat domain I item A dibangun.
 *
 * Sampai sekarang admissions.bed_id hanya menyimpan bed TERKINI, tanpa
 * riwayat. Akibatnya inpatient.v_room_charge menghitung SELURUH hari
 * menginap dengan tarif kamar terkini — jadi begitu pasien pindah kelas di
 * tengah rawat, hari-hari sebelumnya ikut berubah harga secara surut.
 * Pasien yang tiga hari di kelas 3 lalu pindah VIP akan tertagih VIP untuk
 * ketiga hari itu juga. Itu salah tagih, bukan sekadar laporan kurang
 * rapi.
 *
 * bed_assignments mencatat satu baris per periode penempatan — pola yang
 * persis sama dengan dpjp_history yang sudah ada di modul ini: yang lama
 * ditutup (released_at diisi), yang baru dibuka. admissions.bed_id tetap
 * ada sebagai cache bed terkini, seperti admissions.dpjp_name.
 *
 * Migrasi ini juga MENGISI riwayat untuk admisi yang sudah terlanjur ada,
 * memakai bed terkini sejak tanggal masuk — satu-satunya yang bisa
 * diketahui secara jujur dari data yang tersimpan. Untuk admisi yang belum
 * pernah pindah kamar (semua, sejauh ini) hasilnya memang benar.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        Schema::create(self::S . '.bed_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('admission_id')->constrained(self::S . '.admissions')->cascadeOnDelete();
            $table->foreignId('bed_id')->constrained(self::S . '.beds');

            $table->timestampTz('assigned_at');
            $table->timestampTz('released_at')->nullable()->comment('Null = masih ditempati');

            $table->string('reason', 255)->nullable()->comment('Alasan pindah, mis. naik kelas atau butuh isolasi');
            $table->unsignedBigInteger('changed_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();

            $table->index(['admission_id', 'assigned_at']);
        });

        DB::statement('ALTER TABLE ' . self::S . '.bed_assignments ADD CONSTRAINT bed_assignments_urutan_check
            CHECK (released_at IS NULL OR released_at >= assigned_at)');

        // Satu admisi hanya boleh punya satu penempatan yang masih terbuka.
        DB::statement('CREATE UNIQUE INDEX bed_assignments_terbuka_unique ON ' . self::S . '.bed_assignments (admission_id)
            WHERE released_at IS NULL');

        // Isi riwayat untuk admisi yang sudah ada sebelum tabel ini lahir.
        DB::statement('INSERT INTO ' . self::S . '.bed_assignments
                (admission_id, bed_id, assigned_at, released_at, reason, created_at, updated_at)
            SELECT a.id, a.bed_id, a.admitted_at, a.discharged_at,
                   \'Diisi otomatis saat riwayat penempatan bed ditambahkan\', now(), now()
              FROM ' . self::S . '.admissions a');

        $this->rebuildRoomChargeView();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_room_charge');

        // Kembalikan view versi lama yang memakai bed terkini.
        DB::statement('CREATE VIEW ' . self::S . '.v_room_charge AS
            SELECT a.registration_id, a.id AS admission_id, hari::date AS charge_date,
                   r.room_number, r.room_class, b.bed_number,
                   r.daily_rate AS unit_price, 1 AS quantity, r.daily_rate AS amount
            FROM ' . self::S . '.admissions a
            JOIN ' . self::S . '.beds  b ON b.id = a.bed_id
            JOIN ' . self::S . '.rooms r ON r.id = b.room_id
            CROSS JOIN LATERAL generate_series(
                a.admitted_at::date,
                CASE WHEN a.discharged_at IS NULL THEN now()::date
                     ELSE GREATEST(a.admitted_at::date, a.discharged_at::date - 1) END,
                interval \'1 day\'
            ) AS hari');

        Schema::dropIfExists(self::S . '.bed_assignments');
    }

    /**
     * Tiap hari menginap kini mengambil tarif dari bed yang SUNGGUH
     * ditempati hari itu, bukan bed terkini.
     *
     * Kalau pasien pindah kamar di tengah hari, hari itu ditagihkan ke
     * kamar yang ditempatinya sampai malam — penempatan dengan assigned_at
     * paling akhir pada hari tersebut. Alasannya: tarif kamar adalah tarif
     * per malam, dan kamar yang ditempati semalaman itulah yang dipakai.
     */
    private function rebuildRoomChargeView(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_room_charge');

        DB::statement('CREATE VIEW ' . self::S . '.v_room_charge AS
            WITH hari_rawat AS (
                SELECT a.id AS admission_id,
                       a.registration_id,
                       hari::date AS charge_date
                  FROM ' . self::S . '.admissions a
            CROSS JOIN LATERAL generate_series(
                       a.admitted_at::date,
                       CASE WHEN a.discharged_at IS NULL THEN now()::date
                            ELSE GREATEST(a.admitted_at::date, a.discharged_at::date - 1) END,
                       interval \'1 day\'
                   ) AS hari
            )
            SELECT h.registration_id,
                   h.admission_id,
                   h.charge_date,
                   r.room_number,
                   r.room_class,
                   b.bed_number,
                   r.daily_rate AS unit_price,
                   1            AS quantity,
                   r.daily_rate AS amount
              FROM hari_rawat h
              JOIN LATERAL (
                   SELECT ba.bed_id
                     FROM ' . self::S . '.bed_assignments ba
                    WHERE ba.admission_id = h.admission_id
                      AND ba.assigned_at::date <= h.charge_date
                      AND (ba.released_at IS NULL OR ba.released_at::date >= h.charge_date)
                 ORDER BY ba.assigned_at DESC
                    LIMIT 1
              ) pilih ON true
              JOIN ' . self::S . '.beds  b ON b.id = pilih.bed_id
              JOIN ' . self::S . '.rooms r ON r.id = b.room_id');
    }
};
