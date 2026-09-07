<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master jenis berkas digital rekam medis (domain M item L).
 *
 * Padanan master_berkas_digital Khanza (kode + nama). Tinggal di
 * catalog karena bentuknya persis data referensi seperti master jenis
 * cairan dan katalog observasi: dipakai konteks lain, diubah bagian
 * rekam medis, dan tidak boleh menuntut migrasi tiap kali jenis berkas
 * bertambah.
 *
 * KATEGORI DITAMBAHKAN, TIDAK ADA DI KHANZA. Yang menentukan berapa
 * lama sebuah berkas disimpan bukan namanya melainkan jenisnya:
 * persetujuan tindakan dan ringkasan pulang punya masa simpan yang
 * berbeda dari hasil penunjang. Tanpa kategori, aturan retensi harus
 * ditulis per kode berkas dan akan tertinggal begitu kode baru dibuat.
 */
return new class extends Migration
{
    private const S = 'catalog';

    public function up(): void
    {
        Schema::create(self::S.'.document_types', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('category', 30)->default('lainnya')
                ->comment('persetujuan, ringkasan, hasil-penunjang, surat, identitas, lainnya');

            // Berkas yang wajib bertanda tangan basah tetap perlu dipindai
            // meski rekam medisnya elektronik — persetujuan tindakan salah
            // satunya. Penandanya di sini supaya bisa dilaporkan.
            $table->boolean('needs_wet_signature')->default(false);

            $table->boolean('is_permanent')->default(false)
                ->comment('Berkas yang tidak ikut dimusnahkan meski masa retensi lewat');

            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['category', 'is_active']);
        });

        DB::statement('ALTER TABLE '.self::S.".document_types
            ADD CONSTRAINT document_types_category_check
            CHECK (category IN ('persetujuan','ringkasan','hasil-penunjang','surat','identitas','lainnya'))");

        DB::statement('CREATE VIEW '.self::S.'.v_document_type AS
            SELECT code, name, category, needs_wet_signature, is_permanent, is_active
              FROM '.self::S.'.document_types');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_document_type');
        Schema::dropIfExists(self::S.'.document_types');
    }
};
