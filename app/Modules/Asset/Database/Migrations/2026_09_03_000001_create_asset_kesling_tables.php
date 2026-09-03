<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kesehatan lingkungan (kesling): pencatatan limbah B3, mutu air limbah,
 * pemakaian air, dan pest control — syarat perizinan lingkungan dan
 * akreditasi RS, dilaporkan berkala ke instansi lingkungan hidup.
 *
 * Beda bentuk dari CSSD/pemeliharaan: ini bukan siklus dengan status yang
 * berpindah, melainkan catatan pengukuran berkala — sekali dicatat, jadi
 * bagian riwayat kepatuhan yang tidak diubah lagi. environmental_
 * measurements dan pest_control_visits karena itu append-only (tidak ada
 * update/delete di service layer), konsisten dengan pola ledger seperti
 * audit_logs/stock_movements di seluruh sistem ini.
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::create(self::S . '.environmental_measurements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('category', 20)->comment('limbah-b3-cair, limbah-b3-padat, limbah-domestik, mutu-air-limbah, air-pdam, air-tanah');
            $table->string('parameter', 50)->nullable()->comment('Mis. BOD/COD/TSS/pH untuk mutu-air-limbah — null untuk kategori volume/berat sederhana');
            $table->date('measured_on');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20)->comment('kg, liter, m3, mg/L, dst.');

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();

            $table->index(['category', 'measured_on']);
        });

        DB::statement("ALTER TABLE " . self::S . ".environmental_measurements ADD CONSTRAINT environmental_measurements_category_check
            CHECK (category IN ('limbah-b3-cair','limbah-b3-padat','limbah-domestik','mutu-air-limbah','air-pdam','air-tanah'))");

        Schema::create(self::S . '.pest_control_visits', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->date('visited_on');
            $table->string('location', 150);
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar');

            $table->text('findings');
            $table->text('action_taken');
            $table->string('vendor', 150)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampsTz();

            $table->index('visited_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.pest_control_visits');
        Schema::dropIfExists(self::S . '.environmental_measurements');
    }
};
