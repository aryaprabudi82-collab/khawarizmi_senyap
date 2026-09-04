<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * master_berkas_pegawai (jenis berkas) dan berkas_kepegawaian (berkas
 * pegawai sungguhan) — domain C, paket "kepegawaian", genuinely hr.
 *
 * Fitur unggah berkas PERTAMA di seluruh simrs-mandiri — belum ada
 * preseden lain untuk ditiru langsung. Disimpan di disk 'local' (default
 * Laravel, storage/app/private, TIDAK lewat symlink storage:link publik)
 * karena berkas kepegawaian (KTP, ijazah, SIP/STR) sensitif — diunduh
 * lewat rute yang diperiksa permission-nya (EmployeeDocumentController::
 * download()), bukan URL statis yang bisa ditebak siapa saja.
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        $this->createDocumentTypes();
        $this->createEmployeeDocuments();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.employee_documents');
        Schema::dropIfExists(self::S . '.document_types');
    }

    private function createDocumentTypes(): void
    {
        Schema::create(self::S . '.document_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_required')->default(false)->comment('Wajib ada di berkas tiap pegawai, mis. KTP/ijazah');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    private function createEmployeeDocuments(): void
    {
        Schema::create(self::S . '.employee_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('employee_id')->constrained(self::S . '.employees');
            $table->foreignId('document_type_id')->constrained(self::S . '.document_types');
            $table->string('document_type_name', 100)->comment('Disalin saat unggah, bukan dirujuk — jenis berkas bisa direvisi kemudian');

            $table->string('document_number', 60)->nullable()->comment('mis. No. SIP/STR/ijazah');
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable()->comment('Penting untuk SIP/STR yang perlu diperpanjang');

            $table->string('file_path', 255)->comment('Path relatif di disk local, bukan URL publik');
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size')->comment('Byte');

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 150)->nullable();
            $table->timestampTz('uploaded_at');
            $table->string('note', 255)->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'document_type_id']);
        });
    }
};
