<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain L item C: kunjungan ikut dipaparkan pada rincian biaya.
 *
 * v_charge_detail dibuat di domain I item E untuk memetakan pendapatan ke
 * akun, jadi ia berpusat pada TAGIHAN. Klaim INA-CBG bertanya berbeda:
 * berapa biaya rumah sakit untuk satu KUNJUNGAN — itulah angka yang
 * dibandingkan dengan tarif CBG.
 *
 * Ditambahkan ke kontrak yang sudah ada, bukan diterbitkan sebagai view
 * baru: pertanyaannya masih tentang baris biaya yang sama. Kolomnya
 * ditaruh di akhir karena CREATE OR REPLACE VIEW PostgreSQL hanya
 * mengizinkan penambahan di belakang.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        $this->rebuild(', i.registration_id');
    }

    public function down(): void
    {
        $this->rebuild('');
    }

    private function rebuild(string $tambahan): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_charge_detail AS
            SELECT c.id AS charge_line_id,
                   c.invoice_id,
                   c.charged_at,
                   c.source_type,
                   c.description,
                   c.amount,
                   i.care_type,
                   p.kind AS payer_kind,
                   u.unit_name' . $tambahan . '
              FROM ' . self::S . '.charge_lines c
              JOIN ' . self::S . ".invoices i ON i.id = c.invoice_id AND i.status <> 'void'
         LEFT JOIN catalog.payers p ON p.id = i.payer_id
         LEFT JOIN encounter.v_registration_summary u ON u.id = i.registration_id");
    }
};
