<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain J item A: kontrak baca order penunjang untuk konteks reporting.
 *
 * orders sudah menerbitkan v_order_charge, tapi itu kontrak untuk BILLING
 * — isinya per item pemeriksaan yang sudah terverifikasi berikut harganya.
 * Laporan kunjungan penunjang menghitung hal berbeda: berapa PERMINTAAN
 * yang masuk, per kategori, per jenis rawat, termasuk yang belum selesai
 * maupun yang dibatalkan. Memakai v_order_charge untuk itu akan
 * menghasilkan angka yang terlalu kecil tanpa terlihat salah.
 *
 * Satu baris per order (bukan per item), karena yang dihitung kunjungan
 * permintaannya. Order yang dihapus lunak tidak ikut.
 */
return new class extends Migration
{
    private const S = 'orders';

    public function up(): void
    {
        DB::statement('CREATE VIEW ' . self::S . '.v_order_summary AS
            SELECT o.id            AS order_id,
                   o.order_number,
                   o.registration_id,
                   o.patient_id,
                   o.patient_mrn,
                   o.patient_name,
                   o.unit_name,
                   o.category,
                   o.status,
                   o.requesting_practitioner_id,
                   o.requesting_practitioner_name,
                   o.requested_at,
                   o.verified_at
              FROM ' . self::S . '.orders o
             WHERE o.deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_order_summary');
    }
};
