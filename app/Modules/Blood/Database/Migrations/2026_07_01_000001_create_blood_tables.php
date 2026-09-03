<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks blood: Unit Transfusi Darah.
 *
 * Satu unit darah dilacak individual (bukan sekadar kuantitas fungibel
 * seperti stok obat/barang) karena identitasnya — golongan darah, siapa
 * pendonornya, kapan kedaluwarsa — melekat pada unit itu sendiri, bukan
 * bisa dipertukarkan begitu saja antar unit. Siklus statusnya ketat:
 * karantina (baru diambil, belum lolos skrining) -> tersedia (lolos,
 * boleh dikeluarkan) -> dikeluarkan (sudah ditransfusikan, final) — atau
 * bisa berbelok ke ditahan (hasil skrining meragukan) atau ditolak
 * (gagal skrining/rusak). unit_status_logs mencatat setiap perpindahan
 * sebagai jejak audit, konsisten dengan pola ledger append-only di
 * seluruh sistem ini.
 */
return new class extends Migration
{
    private const S = 'blood';

    public function up(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create(self::S . '.donors', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('donor_number', 24)->unique();
            $table->string('name', 150);
            $table->char('blood_type', 2)->comment('A, B, AB, O');
            $table->char('rhesus', 1)->comment('+ atau -');
            $table->date('birth_date')->nullable();
            $table->char('sex', 1)->comment('L atau P');
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['blood_type', 'rhesus']);
        });

        DB::statement("ALTER TABLE " . self::S . ".donors ADD CONSTRAINT donors_blood_type_check
            CHECK (blood_type IN ('A','B','AB','O'))");
        DB::statement("ALTER TABLE " . self::S . ".donors ADD CONSTRAINT donors_rhesus_check
            CHECK (rhesus IN ('+','-'))");
        DB::statement("ALTER TABLE " . self::S . ".donors ADD CONSTRAINT donors_sex_check
            CHECK (sex IN ('L','P'))");

        Schema::create(self::S . '.blood_units', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('unit_number', 24)->unique();
            $table->foreignId('donor_id')->nullable()->constrained(self::S . '.donors');

            $table->char('blood_type', 2)->comment('A, B, AB, O — disalin dari donor saat pengambilan, tidak boleh ikut berubah kalau data donor dikoreksi kemudian');
            $table->char('rhesus', 1);
            $table->string('component', 20)->comment('whole-blood, prc, plasma, platelet');
            $table->unsignedSmallInteger('volume_ml');

            $table->timestampTz('collected_at');
            $table->date('expiry_date');
            $table->string('status', 20)->default('karantina');

            $table->timestampsTz();

            $table->index(['blood_type', 'rhesus', 'status']);
            $table->index('expiry_date');
        });

        DB::statement("ALTER TABLE " . self::S . ".blood_units ADD CONSTRAINT blood_units_blood_type_check
            CHECK (blood_type IN ('A','B','AB','O'))");
        DB::statement("ALTER TABLE " . self::S . ".blood_units ADD CONSTRAINT blood_units_rhesus_check
            CHECK (rhesus IN ('+','-'))");
        DB::statement("ALTER TABLE " . self::S . ".blood_units ADD CONSTRAINT blood_units_component_check
            CHECK (component IN ('whole-blood','prc','plasma','platelet'))");
        DB::statement("ALTER TABLE " . self::S . ".blood_units ADD CONSTRAINT blood_units_status_check
            CHECK (status IN ('karantina','tersedia','ditahan','dikeluarkan','kedaluwarsa','ditolak'))");

        Schema::create(self::S . '.unit_status_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('blood_unit_id')->constrained(self::S . '.blood_units');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('changed_at');

            $table->index('blood_unit_id');
        });

        Schema::create(self::S . '.transfusion_issues', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('issue_number', 24)->unique();
            $table->foreignId('blood_unit_id')->unique()->constrained(self::S . '.blood_units')->comment('unique — satu unit hanya bisa dikeluarkan sekali');

            $table->unsignedBigInteger('patient_id')->nullable()->comment('ID pasien identity, referensi longgar');
            $table->unsignedBigInteger('registration_id')->nullable()->comment('ID registrasi encounter, referensi longgar');
            $table->string('patient_name', 150);

            $table->text('indication')->nullable()->comment('Indikasi klinis transfusi');
            $table->unsignedBigInteger('issued_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('issued_at');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.transfusion_issues');
        Schema::dropIfExists(self::S . '.unit_status_logs');
        Schema::dropIfExists(self::S . '.blood_units');
        Schema::dropIfExists(self::S . '.donors');
        Schema::dropIfExists(self::S . '.number_sequences');
    }
};
