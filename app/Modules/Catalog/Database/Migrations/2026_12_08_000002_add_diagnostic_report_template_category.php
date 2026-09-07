<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kategori template baru: hasil pemeriksaan (domain M item G).
 *
 * Butir khas modalitas — irama dan aksis pada EKG, diameter biparietal
 * pada USG kandungan — memakai mekanisme template yang sama seperti
 * asesmen dan skrining. Yang membedakan hanya siapa yang mengisinya dan
 * kerangka apa yang mengelilinginya, dan kerangka itulah yang jadi tabel
 * tersendiri.
 *
 * NAMA CONSTRAINT DIAMBIL DARI pg_constraint, BUKAN DITEBAK. Menebak nama
 * lalu memakai DROP CONSTRAINT IF EXISTS akan "berhasil" tanpa menghapus
 * apa pun, dan meninggalkan dua CHECK yang saling bertentangan hidup
 * berdampingan — kesalahan yang pernah terjadi di proyek ini.
 */
return new class extends Migration
{
    private const LAMA = [
        'asesmen-medis', 'asesmen-keperawatan', 'skrining',
        'pengkajian-lanjutan', 'checklist', 'catatan',
    ];

    private const BARU = [
        'asesmen-medis', 'asesmen-keperawatan', 'skrining',
        'pengkajian-lanjutan', 'checklist', 'catatan', 'hasil-pemeriksaan',
    ];

    public function up(): void
    {
        $this->gantiCheck(self::BARU);
    }

    public function down(): void
    {
        $this->gantiCheck(self::LAMA);
    }

    /**
     * @param  array<int, string>  $nilai
     */
    private function gantiCheck(array $nilai): void
    {
        foreach (DB::select("SELECT conname FROM pg_constraint
            WHERE conrelid = 'catalog.form_templates'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%category%'") as $constraint) {
            DB::statement("ALTER TABLE catalog.form_templates DROP CONSTRAINT {$constraint->conname}");
        }

        DB::statement("ALTER TABLE catalog.form_templates
            ADD CONSTRAINT form_templates_category_check
            CHECK (category IN ('" . implode("','", $nilai) . "'))");
    }
};
