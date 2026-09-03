<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks inpatient (rawat inap) — bounded context ke-19, domain A/K Khanza.
 *
 * Wave 1 rawat inap ini SENGAJA sempit: kamar/bed, admisi (masuk-keluar), dan
 * status bed (tersedia-terisi-dibersihkan), bukan replika penuh ranap Khanza
 * (~70 kode tersebar di domain A/B/D/I/J/K/L/M/O/P — nursing notes, billing
 * per-hari, SIRANAP, RL4A, dst.). Ranap adalah alur klinis yang jauh lebih
 * besar dari rawat jalan; ini fondasinya (siapa dirawat, di kamar/bed mana,
 * sejak kapan), bukan seluruh sistemnya.
 *
 * TIDAK mendaftarkan pasien sendiri — "permintaan_ranap" (Khanza domain A)
 * tetap di konteks encounter: registrasi ranap adalah registrasi biasa
 * dengan care_type='ranap' (kolom ini sudah ada sejak encounter dibangun,
 * cuma belum pernah dipakai lewat UI). Modul ini membaca registrasi ranap
 * yang belum dapat kamar lewat encounter.v_registration_summary, lalu
 * menambahkan hal yang genuinely baru: alokasi kamar/bed dan siklus rawat.
 *
 * TIDAK menulis balik ke encounter.registrations.status — konsisten dengan
 * seluruh konteks lain (order, billing, dst.) yang tidak pernah menyentuh
 * baris registrasi, admissions.status adalah siklus hidupnya sendiri.
 *
 * DPJP dan nama pasien didenormalisasi dari registrasi saat admisi dibuat
 * (snapshot, bukan referensi hidup) — konsisten dengan pola encounter
 * sendiri. Mengganti DPJP di tengah rawatan belum ada aksinya di Wave 1 ini.
 *
 * daily_rate di kamar bukan tarif bitemporal seperti catalog.service_tariffs
 * — cuma angka statis untuk estimasi, karena billing_ranap (akumulasi biaya
 * harian, beda bentuk total dari billing ralan yang satu kunjungan-satu
 * tagihan) sengaja belum digarap wave ini.
 */
return new class extends Migration
{
    private const S = 'inpatient';

    public function up(): void
    {
        $this->createRooms();
        $this->createBeds();
        $this->createAdmissions();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.admissions');
        Schema::dropIfExists(self::S . '.number_sequences');
        Schema::dropIfExists(self::S . '.beds');
        Schema::dropIfExists(self::S . '.rooms');
    }

    private function createRooms(): void
    {
        Schema::create(self::S . '.rooms', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('room_number', 20)->unique();
            $table->string('room_class', 20)->comment('vip, kelas-1, kelas-2, kelas-3, icu, isolasi');
            $table->unsignedBigInteger('unit_id')->nullable()->comment('ID unit organization, referensi longgar — bangsal/ruang');
            $table->string('unit_name', 150)->nullable();

            $table->decimal('daily_rate', 12, 2)->default(0)->comment('Estimasi tarif/hari, bukan tarif bitemporal — lihat catatan migrasi');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE " . self::S . ".rooms ADD CONSTRAINT rooms_class_check
            CHECK (room_class IN ('vip','kelas-1','kelas-2','kelas-3','icu','isolasi'))");
    }

    /**
     * Satu baris per bed fisik, bukan per kamar — kamar kelas 3 lazimnya
     * berisi beberapa bed, masing-masing diisi/dikosongkan independen.
     */
    private function createBeds(): void
    {
        Schema::create(self::S . '.beds', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('room_id')->constrained(self::S . '.rooms')->cascadeOnDelete();
            $table->string('bed_number', 10);
            $table->string('status', 20)->default('tersedia')
                ->comment('tersedia, terisi, dibersihkan, tidak-aktif');

            $table->timestampsTz();

            $table->unique(['room_id', 'bed_number']);
            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".beds ADD CONSTRAINT beds_status_check
            CHECK (status IN ('tersedia','terisi','dibersihkan','tidak-aktif'))");
    }

    private function createAdmissions(): void
    {
        Schema::create(self::S . '.admissions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('admission_number', 24)->unique();
            $table->unsignedBigInteger('registration_id')->unique()->comment('ID registrasi encounter care_type=ranap, referensi longgar');

            $table->unsignedBigInteger('patient_id')->comment('ID pasien identity, referensi longgar');
            $table->string('patient_mrn', 20);
            $table->string('patient_name', 150);

            $table->foreignId('bed_id')->constrained(self::S . '.beds');
            $table->unsignedBigInteger('dpjp_practitioner_id')->nullable()->comment('ID praktisi organization, referensi longgar');
            $table->string('dpjp_name', 150)->nullable();

            $table->timestampTz('admitted_at');
            $table->timestampTz('discharged_at')->nullable();
            $table->string('discharge_status', 20)->nullable()->comment('sembuh, rujuk, aps, meninggal, lain');
            $table->string('status', 20)->default('dirawat');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('admitted_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->unsignedBigInteger('discharged_by')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'admitted_at']);
            $table->index('patient_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".admissions ADD CONSTRAINT admissions_status_check
            CHECK (status IN ('dirawat','pulang'))");
        DB::statement("ALTER TABLE " . self::S . ".admissions ADD CONSTRAINT admissions_discharge_status_check
            CHECK (discharge_status IS NULL OR discharge_status IN ('sembuh','rujuk','aps','meninggal','lain'))");
        DB::statement("ALTER TABLE " . self::S . ".admissions ADD CONSTRAINT admissions_discharge_order_check
            CHECK (discharged_at IS NULL OR discharged_at >= admitted_at)");

        // Pengalokasi admission_number, tahun dibakar ke kunci counter — pola
        // yang sama dipakai correspondence, quality, blood, asset.
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }
};
