<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain I item E: dua kontrak baru untuk konteks finance.
 *
 * Sampai sekarang finance hanya membaca billing.v_settled_invoice — cukup
 * untuk memposting satu jurnal per tagihan, tapi tidak cukup untuk
 * memetakan uang ke akun: pemetaan itu butuh tahu CARA BAYAR-nya (kode
 * pembayaran_akun_bayar, padanan tabel akun_bayar Khanza yang memetakan
 * nama_bayar ke kd_rek) dan JENIS BIAYA-nya (kode pendapatan_per_akun).
 *
 * Keduanya sengaja hanya memaparkan yang sah dihitung: pembayaran yang
 * dibatalkan dan tagihan yang di-void tidak muncul sama sekali, supaya
 * konteks finance tidak perlu mengingat aturan itu sendiri.
 */
return new class extends Migration
{
    private const S = 'billing';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_payment_detail AS
            SELECT y.id            AS payment_id,
                   y.invoice_id,
                   y.payment_number,
                   y.paid_at,
                   y.amount,
                   y.method,
                   y.received_by,
                   y.received_by_name,
                   i.care_type,
                   i.payer_kind,
                   i.unit_name
              FROM ' . self::S . '.payments y
              JOIN ' . self::S . '.invoices i ON i.id = y.invoice_id
             WHERE y.voided_at IS NULL
               AND i.status <> \'void\'');

        DB::statement('CREATE VIEW ' . self::S . '.v_charge_detail AS
            SELECT c.id            AS charge_line_id,
                   c.invoice_id,
                   c.charged_at,
                   c.source_type,
                   c.description,
                   c.amount,
                   i.care_type,
                   i.payer_kind,
                   i.unit_name
              FROM ' . self::S . '.charge_lines c
              JOIN ' . self::S . '.invoices i ON i.id = c.invoice_id
             WHERE i.status <> \'void\'');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_charge_detail');
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_payment_detail');
    }
};
