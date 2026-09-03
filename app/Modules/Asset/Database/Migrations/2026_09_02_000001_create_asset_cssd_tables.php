<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSSD (Central Sterile Supply Department) — sirkulasi set instrumen
 * steril. Beda bentuk dari registri aset/pemeliharaan di migrasi
 * sebelumnya: instrumen CSSD bukan aset tunggal yang dilacak sekali lalu
 * diam, tapi berputar berulang (kotor -> diproses -> steril ->
 * didistribusikan -> kotor lagi setelah dipakai). Satu baris di
 * cssd_circulations mewakili SATU putaran, bukan status yang terus
 * ditimpa selamanya — putaran berikutnya untuk set yang sama adalah baris
 * baru, supaya riwayat tiap putaran tetap utuh (kapan disterilkan, metode
 * apa, ke unit mana), konsisten dengan pola "satu baris per kejadian"
 * di seluruh sistem ini (mis. bpjs_sep, blood.blood_units).
 */
return new class extends Migration
{
    private const S = 'asset';

    public function up(): void
    {
        Schema::create(self::S . '.cssd_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 150)->comment('Nama set instrumen, mis. "Set Bedah Minor", "Set Ganti Verband"');
            $table->boolean('is_active')->default(true);
        });

        Schema::create(self::S . '.cssd_circulations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('circulation_number', 24)->unique();
            $table->foreignId('cssd_item_id')->constrained(self::S . '.cssd_items');

            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar — unit asal/tujuan');
            $table->string('unit_name', 150)->comment('Disalin saat diterima — nama unit tidak boleh ikut berubah kalau data organization berubah kemudian');

            $table->string('status', 20)->default('kotor');
            $table->timestampTz('received_at')->comment('Waktu diterima kotor dari unit');
            $table->timestampTz('processed_at')->nullable()->comment('Mulai dicuci/dikemas/disterilkan');
            $table->timestampTz('sterilized_at')->nullable();
            $table->timestampTz('distributed_at')->nullable();
            $table->string('sterilization_method', 30)->nullable()->comment('autoklaf-uap, etilen-oksida, plasma, dst.');

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable()->comment('ID pengguna platform, referensi longgar');

            $table->timestampsTz();

            $table->index(['cssd_item_id', 'status']);
        });

        DB::statement("ALTER TABLE " . self::S . ".cssd_circulations ADD CONSTRAINT cssd_circulations_status_check
            CHECK (status IN ('kotor','diproses','steril','didistribusikan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.cssd_circulations');
        Schema::dropIfExists(self::S . '.cssd_items');
    }
};
