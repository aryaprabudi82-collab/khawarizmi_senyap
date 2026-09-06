<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan kode lokal ke kode SATUSEHAT (domain L item D) — 12 kode.
 *
 * Menaungi satu_sehat_mapping_departemen, _lokasi, _vaksin, _obat,
 * _radiologi, _lab, dan keenam _kptl_tindakan_* / _kptl_tarif_kamar.
 *
 * SATU TABEL, DIBEDAKAN JENIS PEMETAAN. Khanza memberi menu terpisah untuk
 * tiap jenis (departemen, lokasi, vaksin, obat, tindakan ralan, tindakan
 * ranap, tindakan radiologi, tindakan lab, tindakan operasi, tarif kamar,
 * radiologi, lab) — dua belas layar untuk satu pekerjaan yang sama:
 * mencocokkan kode kita dengan kode standar. Dua belas tabel berarti dua
 * belas layar pemetaan, dua belas aturan pencarian, dan jenis ketiga belas
 * menuntut migrasi baru.
 *
 * KODE STANDARNYA BEDA-BEDA SISTEMNYA, dan itu disimpan terang: SNOMED CT
 * untuk tindakan dan kondisi, LOINC untuk pemeriksaan lab dan radiologi,
 * KFA untuk obat dan vaksin. Menyimpan kodenya tanpa menyebut sistemnya
 * akan membuat kode LOINC dikirim sebagai SNOMED dan ditolak SATUSEHAT
 * dengan pesan yang sulit ditelusuri.
 *
 * PEMETAAN DIBIARKAN KOSONG. Kode SNOMED/LOINC/KFA yang benar hanya bisa
 * ditetapkan orang yang memahami isi layanannya; menebaknya berarti
 * mengirim data klinis pasien ke platform nasional dengan kode yang salah,
 * dan data itu ikut terbaca fasilitas kesehatan lain yang merawat pasien
 * yang sama. Yang belum dipetakan tidak dikirim, dan jumlahnya dilaporkan.
 */
return new class extends Migration
{
    private const S = 'integration';

    /** Jenis pemetaan yang dikenal. */
    private const JENIS = [
        'departemen', 'lokasi', 'vaksin', 'obat',
        'tindakan-ralan', 'tindakan-ranap', 'tindakan-radiologi',
        'tindakan-lab', 'tindakan-operasi', 'tarif-kamar',
        'radiologi', 'lab',
    ];

    /** Sistem kode standar yang dipakai SATUSEHAT. */
    private const SISTEM = ['snomed', 'loinc', 'kfa', 'icd10', 'icd9', 'internal'];

    public function up(): void
    {
        Schema::create(self::S . '.satusehat_code_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('mapping_type', 30)->comment('departemen/lokasi/vaksin/obat/tindakan-*/radiologi/lab');

            $table->string('local_code', 40)->comment('Kode kita');
            $table->string('local_name', 200)->nullable();

            // Kode standar. Kosong = belum dipetakan, dan itu keadaan yang
            // sah — bukan sesuatu yang boleh ditebak.
            $table->string('code_system', 20)->nullable()->comment('snomed/loinc/kfa — sistem kodenya, wajib bila kode diisi');
            $table->string('standard_code', 60)->nullable();
            $table->string('standard_display', 250)->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('mapped_by')->nullable();
            $table->timestampTz('mapped_at')->nullable();
            $table->timestampsTz();

            // Satu kode lokal hanya boleh punya satu pemetaan per jenis.
            $table->unique(['mapping_type', 'local_code'], 'satusehat_pemetaan_unik');
            $table->index(['mapping_type', 'standard_code']);
        });

        DB::statement('ALTER TABLE ' . self::S . ".satusehat_code_mappings
            ADD CONSTRAINT satusehat_mapping_type_check
            CHECK (mapping_type IN ('" . implode("','", self::JENIS) . "'))");

        DB::statement('ALTER TABLE ' . self::S . ".satusehat_code_mappings
            ADD CONSTRAINT satusehat_code_system_check
            CHECK (code_system IS NULL OR code_system IN ('" . implode("','", self::SISTEM) . "'))");

        // Kode standar tanpa menyebut sistemnya akan dikirim dengan sistem
        // yang salah dan ditolak SATUSEHAT dengan pesan yang sulit
        // ditelusuri. Keduanya harus diisi bersama atau kosong bersama.
        DB::statement('ALTER TABLE ' . self::S . '.satusehat_code_mappings
            ADD CONSTRAINT satusehat_kode_dan_sistem_sepasang
            CHECK ((standard_code IS NULL AND code_system IS NULL)
                OR (standard_code IS NOT NULL AND code_system IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::S . '.satusehat_code_mappings');
    }
};
