<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan konteks catalog: TEMPLATE FORMULIR berikut versinya.
 *
 * Diterbitkan supaya konteks clinical bisa membaca pertanyaan dan aturan
 * skor tanpa menyentuh tabelnya — dan tanpa mengimpor model milik catalog,
 * yang selama ini lolos dari pemeriksaan batas konteks karena
 * pemeriksaannya cuma memindai literal 'schema.tabel'.
 *
 * SELURUH VERSI DITERBITKAN, bukan cuma yang aktif. Clinical justru
 * membutuhkan versi LAMA: formulir yang diisi tahun lalu harus dibaca
 * kembali dengan pertanyaan yang berlaku waktu itu, dan kontrak yang cuma
 * memuat versi aktif akan membuat rekam medis lama kehilangan
 * pertanyaannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW catalog.v_form_template AS
            SELECT
                id          AS template_id,
                code,
                version,
                name,
                category,
                specialty,
                age_group,
                sections,
                scoring,
                note,
                is_active
            FROM catalog.form_templates');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.v_form_template');
    }
};
