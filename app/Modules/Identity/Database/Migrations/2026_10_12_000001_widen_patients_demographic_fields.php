<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit ulang konteks identity (2026-10): 4 dari 4 kode yang tercatat
 * context=identity di katalog (suku_bangsa, bahasa_pasien,
 * perusahaan_pasien, klasifikasi_pasien_ranap) ternyata SAMA SEKALI
 * tidak ada kolom/layar — tidak terdokumentasi di mana pun sebagai
 * already-satisfied atau sengaja dilewati. Semua 4 kelas Java-nya
 * ("Dlg...") adalah dialog master list sederhana (kode+nama), tapi
 * konsisten dengan precedent MODUL INI SENDIRI — religion/marital_
 * status/education/occupation sudah berupa kolom teks bebas di
 * identity.patients, BUKAN tabel master terpisah — 4 kolom ini
 * mengikuti pola yang sama, bukan tabel referensi baru. Digerbangi
 * kode 'pasien' yang sudah ada (form pendaftaran yang sama), sama
 * seperti religion/marital_status/dst. tidak punya gerbang literal
 * sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity.patients', function (Blueprint $table) {
            $table->string('ethnicity', 60)->nullable()->after('religion')->comment('suku_bangsa');
            $table->string('language', 60)->nullable()->after('ethnicity')->comment('bahasa_pasien');
            $table->string('employer', 150)->nullable()->after('occupation')->comment('perusahaan_pasien');
            $table->string('inpatient_classification', 60)->nullable()->after('employer')->comment('klasifikasi_pasien_ranap');
        });
    }

    public function down(): void
    {
        Schema::table('identity.patients', function (Blueprint $table) {
            $table->dropColumn(['ethnicity', 'language', 'employer', 'inpatient_classification']);
        });
    }
};
