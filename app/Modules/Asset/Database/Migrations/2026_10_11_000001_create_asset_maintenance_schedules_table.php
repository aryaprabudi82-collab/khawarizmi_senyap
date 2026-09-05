<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain G Khanza item D (terakhir) dari 4 sub-order yang disepakati —
 * pemeliharaan_inventaris + pemeliharaan_gedung: jadwal pemeliharaan
 * PREVENTIF/terjadwal, beda dari perbaikan REAKTIF (perbaikan_inventaris/
 * permintaan_perbaikan_inventaris, MaintenanceRequest) yang sudah ada
 * sejak Wave 1 — itu menunggu laporan kerusakan, ini mencegahnya lewat
 * jadwal berkala (kalibrasi alkes, servis AC gedung, dst.).
 *
 * Dua keputusan desain dikonfirmasi user lewat AskUserQuestion:
 *
 *  1. Jadwal berupa INTERVAL BERULANG OTOMATIS (interval_months),
 *     bukan tanggal jatuh tempo manual — next_due_date dihitung ulang
 *     otomatis dari last_performed_at + interval_months tiap kali
 *     pelaksanaan dicatat (lihat AssetMaintenanceScheduleService).
 *  2. SATU tabel dengan target fleksibel (asset_id ATAU location_id,
 *     bukan dua tabel terpisah) — gedung/ruang tidak punya baris
 *     asset.assets sendiri (bukan aset yang dilacak per-unit seperti
 *     alkes), jadi pemeliharaan_gedung memakai location_id sebagai
 *     target, pemeliharaan_inventaris memakai asset_id. Salah satu
 *     WAJIB terisi (CHECK constraint) — satu layar, satu alur kerja,
 *     dibedakan lewat target mana yang terisi. Digerbangi
 *     pemeliharaan_inventaris sebagai wakil (mengikuti pola perbaikan_
 *     inventaris di layar reaktif yang sudah ada) — pemeliharaan_gedung
 *     TIDAK dapat gerbang literal terpisah, umbrella-gate.
 *
 * maintenance_schedule_logs — riwayat pelaksanaan, satu baris per
 * kejadian (pola sama dengan asset_transfers/cssd_circulations).
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::create(self::S . '.maintenance_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('schedule_number', 24)->unique();
            $table->foreignId('asset_id')->nullable()->constrained(self::S . '.assets');
            $table->foreignId('location_id')->nullable()->constrained(self::S . '.locations');

            $table->string('title', 200)->comment('mis. "Kalibrasi Tahunan", "Servis AC Gedung"');
            $table->unsignedSmallInteger('interval_months');

            $table->date('last_performed_at')->nullable();
            $table->date('next_due_date');
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampsTz();

            $table->index('next_due_date');
        });

        DB::statement("ALTER TABLE " . self::S . ".maintenance_schedules ADD CONSTRAINT maintenance_schedules_target_check
            CHECK (asset_id IS NOT NULL OR location_id IS NOT NULL)");

        Schema::create(self::S . '.maintenance_schedule_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('schedule_id')->constrained(self::S . '.maintenance_schedules')->cascadeOnDelete();
            $table->date('performed_at');
            $table->unsignedBigInteger('performed_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->text('notes')->nullable();
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.maintenance_schedule_logs');
        Schema::dropIfExists(self::S . '.maintenance_schedules');
    }
};
