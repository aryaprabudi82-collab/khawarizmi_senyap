<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak register TB (domain O item E).
 *
 * Menaungi 11 kode grafik_tb_* dan memberi isi pada kemenkes_sitt
 * domain J yang sebelumnya baru menghitung diagnosis TB dari kamus
 * ICD — bukan register programnya.
 *
 * STATUS HIV IKUT DITERBITKAN, DAN ITU KEPUTUSAN YANG PERLU DISEBUT.
 * Status HIV adalah data yang paling sensitif dalam register ini, dan
 * pada kontrak lain proyek ini justru menahan data sensitif — uraian
 * insiden pada item B, keadaan klinis dialisis pada item D, identitas
 * donor pada domain N item B.
 *
 * Di sini ia tetap diterbitkan karena sebab yang berbeda: program TB
 * nasional MEWAJIBKAN pelaporan status HIV pasien TB, dan angkanya
 * dipakai menentukan penyediaan ART. Yang diterbitkan pun cuma
 * STATUSNYA sebagai kategori, bukan tanggal tes, bukan hasil tes rinci,
 * dan bukan tautan ke pasiennya sebagai orang — konsumennya menghitung
 * batang, bukan membaca rekam medis.
 *
 * NOMOR REKAM MEDIS DAN NAMA PASIEN TIDAK IKUT. Grafik tidak
 * membutuhkannya, dan register TB yang bisa dibaca per nama lewat layar
 * laporan adalah daftar pengidap yang beredar di luar keperluannya.
 */
return new class extends Migration
{
    private const S = 'clinical';

    public function up(): void
    {
        DB::statement('CREATE VIEW '.self::S.'.v_tb_case AS
            SELECT id,
                   registered_on,
                   report_year,
                   report_quarter,
                   referral_source,
                   diagnosis_type,
                   anatomical_site,
                   treatment_history,
                   hiv_status,
                   hiv_test_result,
                   child_score,
                   drug_source,
                   outcome,
                   outcome_on
              FROM '.self::S.'.tb_cases
             WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_tb_case');
    }
};
