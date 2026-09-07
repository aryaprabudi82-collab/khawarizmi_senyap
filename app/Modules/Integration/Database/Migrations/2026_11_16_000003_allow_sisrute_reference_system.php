<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambahkan Sisrute ke daftar sistem yang referensinya boleh disalin
 * (domain L item P).
 *
 * Mekanisme daftar referensi lahir untuk PENJAMIN, dan Sisrute bukan
 * penjamin — ia sistem rujukan Kemenkes. Yang menyatukan mereka bentuk
 * datanya: daftar kode milik pihak lain yang kita salin dan segarkan
 * seluruhnya. Membangun tabel kedua yang berkolom sama persis hanya karena
 * namanya "penjamin" akan menggandakan aturan penyegaran ke dua tempat,
 * dan aturan yang berlaku di dua tempat cepat atau lambat berbeda.
 *
 * NAMA CONSTRAINT DIAMBIL DARI pg_constraint, BUKAN DITEBAK. Menebak nama
 * lalu memakai DROP CONSTRAINT IF EXISTS akan "berhasil" tanpa menghapus
 * apa pun, dan meninggalkan dua CHECK yang saling bertentangan hidup
 * berdampingan — kesalahan yang pernah terjadi di proyek ini.
 */
return new class extends Migration
{
    private const S = 'integration';

    private const LAMA = ['bpjs', 'bpjs-apotek', 'hfis', 'inhealth'];

    private const BARU = ['bpjs', 'bpjs-apotek', 'hfis', 'inhealth', 'sisrute'];

    public function up(): void
    {
        $this->gantiCheck('payer_references', self::BARU);
        $this->gantiCheck('payer_code_mappings', self::BARU);
    }

    public function down(): void
    {
        $this->gantiCheck('payer_references', self::LAMA);
        $this->gantiCheck('payer_code_mappings', self::LAMA);
    }

    /**
     * @param  array<int, string>  $nilai
     */
    private function gantiCheck(string $tabel, array $nilai): void
    {
        $penuh = self::S . '.' . $tabel;

        foreach (DB::select("SELECT conname FROM pg_constraint
            WHERE conrelid = '{$penuh}'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%payer%'") as $constraint) {
            DB::statement("ALTER TABLE {$penuh} DROP CONSTRAINT {$constraint->conname}");
        }

        DB::statement("ALTER TABLE {$penuh}
            ADD CONSTRAINT {$tabel}_payer_check
            CHECK (payer IN ('" . implode("','", $nilai) . "'))");
    }
};
