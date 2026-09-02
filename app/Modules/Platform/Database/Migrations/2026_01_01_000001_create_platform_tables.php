<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks platform: pengguna, peran, katalog permission, dan jejak audit.
 *
 * Catatan desain:
 *  - Primary key bigint identity. Kode yang dibaca manusia (username, kode peran)
 *    disimpan sebagai kolom unik terpisah, bukan dijadikan primary key.
 *  - Seluruh kolom waktu memakai timestamptz.
 *  - audit_logs dipartisi bulanan sejak awal: pada 2.000 pasien/hari tabel ini
 *    diperkirakan tumbuh ~36 juta baris per tahun, dan partition key tidak bisa
 *    ditambahkan belakangan tanpa menulis ulang seluruh tabel.
 */
return new class extends Migration
{
    private const SCHEMA = 'platform';

    public function up(): void
    {
        $this->createPermissions();
        $this->createRoles();
        $this->createUsers();
        $this->createAuditLogs();
        $this->createPublishedViews();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::SCHEMA . '.v_user_summary');
        DB::statement('DROP TABLE IF EXISTS ' . self::SCHEMA . '.audit_logs CASCADE');

        Schema::dropIfExists(self::SCHEMA . '.user_role');
        Schema::dropIfExists(self::SCHEMA . '.role_permission');
        Schema::dropIfExists(self::SCHEMA . '.users');
        Schema::dropIfExists(self::SCHEMA . '.roles');
        Schema::dropIfExists(self::SCHEMA . '.permissions');
    }

    /**
     * Katalog kapabilitas. Satu baris per permission, bukan satu kolom boolean
     * per permission seperti pada tabel `user` Khanza. Menambah kapabilitas baru
     * berarti menambah baris, bukan mengubah struktur tabel.
     */
    private function createPermissions(): void
    {
        Schema::create(self::SCHEMA . '.permissions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Kode diambil dari access flag Khanza agar peta fungsionalnya tetap terlacak.
            $table->string('code', 120)->unique();
            $table->string('name', 200);

            $table->char('domain_code', 1)->nullable()->comment('Domain A-U pada peta fungsional Khanza');
            $table->string('context', 40)->index()->comment('Bounded context tujuan');
            $table->string('kind', 20)->index()->comment('Master, Transaksi, Laporan, Analytics, Bridging, Pengaturan');
            $table->unsignedTinyInteger('wave')->default(3);

            $table->string('legacy_class', 120)->nullable();
            $table->string('legacy_package', 60)->nullable();

            $table->timestampsTz();
        });
    }

    private function createRoles(): void
    {
        Schema::create(self::SCHEMA . '.roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // Peran bawaan sistem tidak boleh dihapus lewat UI.
            $table->boolean('is_system')->default(false);

            $table->timestampsTz();
        });

        Schema::create(self::SCHEMA . '.role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained(self::SCHEMA . '.roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained(self::SCHEMA . '.permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    private function createUsers(): void
    {
        Schema::create(self::SCHEMA . '.users', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('username', 60)->unique()->comment('Dipetakan ke sAMAccountName saat SSO/AD aktif');
            $table->string('nip', 30)->nullable()->unique()->comment('Nomor induk pegawai, jembatan ke konteks hr');
            $table->string('name', 150);
            $table->string('email', 150)->nullable()->unique();

            $table->string('password')->nullable()->comment('Null bila autentikasi sepenuhnya lewat SSO/AD');
            $table->rememberToken();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('must_change_password')->default(false);
            $table->boolean('mfa_required')->default(false)->comment('Wajib untuk peran berprivilege tinggi');

            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create(self::SCHEMA . '.user_role', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained(self::SCHEMA . '.users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained(self::SCHEMA . '.roles')->cascadeOnDelete();

            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
    }

    /**
     * Jejak audit, dipartisi per bulan berdasarkan created_at.
     *
     * PostgreSQL mewajibkan partition key ikut dalam primary key, karena itu
     * PK-nya (id, created_at) dan bukan (id) saja.
     */
    private function createAuditLogs(): void
    {
        DB::statement('
            CREATE TABLE ' . self::SCHEMA . '.audit_logs (
                id            bigint GENERATED ALWAYS AS IDENTITY,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                user_id       bigint       NULL,
                username      varchar(60)  NULL,
                context       varchar(40)  NOT NULL,
                action        varchar(60)  NOT NULL,
                subject_type  varchar(120) NULL,
                subject_id    varchar(64)  NULL,
                ip_address    varchar(45)  NULL,
                user_agent    text         NULL,
                changes       jsonb        NULL,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        ');

        // Partisi disiapkan 24 bulan ke depan. Perpanjangannya nanti dijadwalkan
        // lewat perintah artisan, bukan diurus manual saat sudah kepepet.
        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = 0; $i < 24; $i++) {
            $from = $start->modify("+{$i} months");
            $to = $from->modify('+1 month');

            DB::statement(sprintf(
                'CREATE TABLE %s.audit_logs_%s PARTITION OF %s.audit_logs FOR VALUES FROM (%s) TO (%s)',
                self::SCHEMA,
                $from->format('Y_m'),
                self::SCHEMA,
                "'" . $from->format('Y-m-d') . "'",
                "'" . $to->format('Y-m-d') . "'"
            ));
        }

        // Penampung baris di luar rentang, supaya INSERT tidak pernah gagal
        // hanya karena partisi belum sempat dibuat.
        DB::statement(
            'CREATE TABLE ' . self::SCHEMA . '.audit_logs_default PARTITION OF '
            . self::SCHEMA . '.audit_logs DEFAULT'
        );

        DB::statement('CREATE INDEX audit_logs_user_id_created_at_idx ON ' . self::SCHEMA . '.audit_logs (user_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_subject_idx ON ' . self::SCHEMA . '.audit_logs (subject_type, subject_id)');
        DB::statement('CREATE INDEX audit_logs_context_action_idx ON ' . self::SCHEMA . '.audit_logs (context, action, created_at DESC)');
    }

    /**
     * Kontrak baca yang diterbitkan konteks platform. Konteks lain boleh JOIN
     * ke view ini, tapi tidak boleh menyentuh platform.users langsung.
     */
    private function createPublishedViews(): void
    {
        DB::statement('
            CREATE VIEW ' . self::SCHEMA . '.v_user_summary AS
            SELECT id, username, nip, name, is_active
            FROM ' . self::SCHEMA . '.users
            WHERE deleted_at IS NULL
        ');
    }
};
