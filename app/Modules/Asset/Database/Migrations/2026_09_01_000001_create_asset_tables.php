<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks asset: registri aset/inventaris dan alur permintaan perbaikan.
 *
 * CSSD dan kesehatan lingkungan (limbah, mutu air, pest control) belum
 * digarap di sini — lihat catatan di config/contexts.php.
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create(self::S . '.categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        Schema::create(self::S . '.locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150)->comment('Ruang/lokasi fisik penempatan aset — lebih rinci dari unit organization');
            $table->boolean('is_active')->default(true);
        });

        Schema::create(self::S . '.assets', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('asset_number', 24)->unique();
            $table->string('name', 200);
            $table->foreignId('category_id')->constrained(self::S . '.categories');
            $table->foreignId('location_id')->nullable()->constrained(self::S . '.locations');
            $table->string('brand', 100)->nullable();

            $table->date('acquisition_date')->nullable();
            $table->decimal('acquisition_value', 14, 2)->nullable();

            $table->string('condition', 20)->default('baik')->comment('baik, rusak-ringan, rusak-berat');
            $table->string('status', 20)->default('aktif')->comment('aktif, dalam-perbaikan, dihapuskan');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index('category_id');
            $table->index('location_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".assets ADD CONSTRAINT assets_condition_check
            CHECK (condition IN ('baik','rusak-ringan','rusak-berat'))");
        DB::statement("ALTER TABLE " . self::S . ".assets ADD CONSTRAINT assets_status_check
            CHECK (status IN ('aktif','dalam-perbaikan','dihapuskan'))");

        Schema::create(self::S . '.maintenance_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('request_number', 24)->unique();
            $table->foreignId('asset_id')->constrained(self::S . '.assets');

            $table->text('description');
            $table->string('status', 20)->default('diajukan');

            $table->unsignedBigInteger('reported_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('assigned_to')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->timestampsTz();

            $table->index(['asset_id', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".maintenance_requests ADD CONSTRAINT maintenance_requests_status_check
            CHECK (status IN ('diajukan','dikerjakan','selesai','ditolak'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.maintenance_requests');
        Schema::dropIfExists(self::S . '.assets');
        Schema::dropIfExists(self::S . '.locations');
        Schema::dropIfExists(self::S . '.categories');
        Schema::dropIfExists(self::S . '.number_sequences');
    }
};
