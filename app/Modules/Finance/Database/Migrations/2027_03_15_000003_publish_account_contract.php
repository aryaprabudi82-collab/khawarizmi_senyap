<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak terbitan: bagan akun, untuk dibaca konteks lain.
 *
 * DIBUAT KARENA UJI BATAS KONTEKS MENANGKAP PELANGGARAN NYATA.
 * `Keuangan\MasterData\ChargeMasterService` membaca
 * `finance.chart_of_accounts` langsung untuk memastikan akun yang
 * dipetakan memang ada. Saya menulis catatan "sementara, akan diganti
 * kontrak view" di kodenya — dan catatan itu TIDAK membuat pelanggarannya
 * sah. Yang berlaku bukan maksud yang tertulis di komentar, melainkan apa
 * yang sungguh dikerjakan kodenya.
 *
 * YANG DITERBITKAN SENGAJA SEDIKIT. Konsumennya cuma butuh menjawab satu
 * pertanyaan: "apakah akun ini ada, aktif, dan boleh dijurnalkan?" Kontrak
 * yang membawa lebih banyak daripada yang dibutuhkan akan dipakai lebih
 * banyak daripada yang dimaksudkan — dan begitu ada konsumen yang
 * menjumlahkan saldo lewat kontrak ini, buku besar punya dua sumber
 * kebenaran.
 *
 * SALDO TIDAK IKUT, dan itu keputusan. Saldo akun adalah hasil hitungan
 * atas jurnal, bukan atribut akun; menerbitkannya di sini akan mengundang
 * konteks lain menghitung saldo sendiri-sendiri dengan cara masing-masing.
 */
return new class extends Migration
{
    private const S = 'finance';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW '.self::S.'.v_account AS
            SELECT a.id,
                   a.code,
                   a.name,
                   a.type,
                   a.klasifikasi,
                   a.parent_id,
                   a.is_active,
                   a.is_postable
              FROM '.self::S.'.chart_of_accounts a');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::S.'.v_account');
    }
};
