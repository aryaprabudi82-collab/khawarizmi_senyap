<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan tindakan (informed consent) dan surat keterangan medis —
 * dua dari sekitar 30 kapabilitas domain P yang sebelumnya sengaja
 * ditinggalkan saat correspondence pertama dibangun (lihat catatan di
 * config/contexts.php): keduanya dokumen klinis, bukan surat-menyurat
 * kantor, dan aslinya butuh alur tanda tangan digital penuh — di sini
 * dibangun sebagai catatan terstruktur (siapa memutuskan apa, kapan,
 * disaksikan siapa) dengan tampilan cetak, TANPA tanda tangan elektronik
 * sungguhan (butuh signature-pad/canvas, di luar cakupan wave ini).
 *
 * Dua tabel, bukan satu — bentuknya cukup beda: persetujuan tindakan
 * punya decision (setuju/menolak) dan witness, surat keterangan punya
 * valid_from/valid_until dan tidak butuh keduanya. Memaksakan satu tabel
 * flexible untuk keduanya berarti banyak kolom nullable yang cuma relevan
 * untuk salah satu jenis.
 */
return new class extends Migration
{
    private const S = 'correspondence';

    public function up(): void
    {
        Schema::create(self::S . '.patient_consents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('consent_number', 24)->unique();
            $table->string('consent_type', 30)->comment('tindakan, penolakan-anjuran-medis, resusitasi, umum');

            $table->unsignedBigInteger('registration_id')->nullable()->comment('ID registrasi encounter, referensi longgar');
            $table->unsignedBigInteger('patient_id')->nullable()->comment('ID pasien identity, referensi longgar');
            $table->string('patient_name', 150);

            $table->text('procedure_description')->comment('Uraian tindakan/keputusan yang disetujui atau ditolak');
            $table->string('decision', 10)->comment('setuju, menolak');
            $table->string('witness_name', 150)->nullable();

            $table->unsignedBigInteger('issued_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('signed_at');
            $table->string('status', 20)->default('aktif');

            $table->timestampsTz();

            $table->index('registration_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".patient_consents ADD CONSTRAINT patient_consents_type_check
            CHECK (consent_type IN ('tindakan','penolakan-anjuran-medis','resusitasi','umum'))");
        DB::statement("ALTER TABLE " . self::S . ".patient_consents ADD CONSTRAINT patient_consents_decision_check
            CHECK (decision IN ('setuju','menolak'))");
        DB::statement("ALTER TABLE " . self::S . ".patient_consents ADD CONSTRAINT patient_consents_status_check
            CHECK (status IN ('aktif','dibatalkan'))");

        Schema::create(self::S . '.medical_certificates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('certificate_number', 24)->unique();
            $table->string('certificate_type', 20)->comment('sehat, sakit, berobat');

            $table->unsignedBigInteger('registration_id')->nullable()->comment('ID registrasi encounter, referensi longgar');
            $table->unsignedBigInteger('patient_id')->nullable()->comment('ID pasien identity, referensi longgar');
            $table->string('patient_name', 150);

            $table->string('purpose', 200)->comment('Keperluan, mis. "untuk keperluan kerja"');
            $table->text('content')->comment('Isi keterangan, mis. diagnosis untuk surat sakit');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->unsignedBigInteger('issued_by')->nullable()->comment('ID pengguna platform, referensi longgar');
            $table->timestampTz('issued_at');
            $table->string('status', 20)->default('diterbitkan');

            $table->timestampsTz();

            $table->index('registration_id');
        });

        DB::statement("ALTER TABLE " . self::S . ".medical_certificates ADD CONSTRAINT medical_certificates_type_check
            CHECK (certificate_type IN ('sehat','sakit','berobat'))");
        DB::statement("ALTER TABLE " . self::S . ".medical_certificates ADD CONSTRAINT medical_certificates_status_check
            CHECK (status IN ('diterbitkan','dibatalkan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.medical_certificates');
        Schema::dropIfExists(self::S . '.patient_consents');
    }
};
