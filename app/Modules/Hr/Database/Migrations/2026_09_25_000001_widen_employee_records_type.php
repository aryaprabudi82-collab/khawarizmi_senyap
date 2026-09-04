<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * kegiatan_ilmiah (Kegiatan Ilmiah & Pelatihan) dan riwayat_penelitian
 * (Riwayat Penelitian) — domain C, paket "kepegawaian", genuinely hr.
 * Bukan tabel baru — hr.employee_records sudah punya bentuk generik yang
 * pas (record_date, title, description, document_number) untuk keduanya:
 * title=nama kegiatan/judul penelitian, description=penyelenggara/jurnal,
 * document_number=no. sertifikat/publikasi. Pola sama dengan penghargaan/
 * peringatan yang sudah lebih dulu berbagi tabel ini lewat record_type.
 */
return new class extends Migration
{
    private const S = 'hr';

    public function up(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.employee_records DROP CONSTRAINT employee_records_type_check');
        DB::statement("ALTER TABLE " . self::S . ".employee_records ADD CONSTRAINT employee_records_type_check
            CHECK (record_type IN ('penghargaan','peringatan','kegiatan_ilmiah','penelitian'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ' . self::S . '.employee_records DROP CONSTRAINT employee_records_type_check');
        DB::statement("ALTER TABLE " . self::S . ".employee_records ADD CONSTRAINT employee_records_type_check
            CHECK (record_type IN ('penghargaan','peringatan'))");
    }
};
