<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konteks correspondence: surat masuk, surat keluar, dan pengumuman
 * e-pasien — korespondensi kantor, bukan formulir persetujuan klinis
 * (lihat catatan di config/contexts.php).
 */
return new class extends Migration
{
    private const S = 'correspondence';

    public function up(): void
    {
        Schema::create(self::S . '.number_sequences', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create(self::S . '.incoming_letters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('letter_number', 24)->unique()->comment('Nomor agenda internal');
            $table->string('reference_number', 60)->nullable()->comment('Nomor surat asli dari pengirim');
            $table->string('sender', 150);
            $table->string('subject', 255);
            $table->string('classification', 20)->default('biasa')->comment('biasa, penting, rahasia, segera');
            $table->date('received_at');
            $table->string('forwarded_to', 150)->nullable()->comment('Unit/pihak tujuan disposisi');

            $table->string('status', 20)->default('diterima');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampsTz();

            $table->index('status');
            $table->index('received_at');
        });

        DB::statement("ALTER TABLE " . self::S . ".incoming_letters ADD CONSTRAINT incoming_letters_classification_check
            CHECK (classification IN ('biasa','penting','rahasia','segera'))");
        DB::statement("ALTER TABLE " . self::S . ".incoming_letters ADD CONSTRAINT incoming_letters_status_check
            CHECK (status IN ('diterima','didisposisikan','diarsipkan'))");

        Schema::create(self::S . '.outgoing_letters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('letter_number', 24)->unique();
            $table->string('recipient', 150);
            $table->string('subject', 255);
            $table->string('classification', 20)->default('biasa');

            $table->string('status', 20)->default('draft');
            $table->date('sent_at')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE " . self::S . ".outgoing_letters ADD CONSTRAINT outgoing_letters_classification_check
            CHECK (classification IN ('biasa','penting','rahasia','segera'))");
        DB::statement("ALTER TABLE " . self::S . ".outgoing_letters ADD CONSTRAINT outgoing_letters_status_check
            CHECK (status IN ('draft','terkirim'))");

        Schema::create(self::S . '.announcements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('title', 200);
            $table->text('body');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampsTz();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.announcements');
        Schema::dropIfExists(self::S . '.outgoing_letters');
        Schema::dropIfExists(self::S . '.incoming_letters');
        Schema::dropIfExists(self::S . '.number_sequences');
    }
};
