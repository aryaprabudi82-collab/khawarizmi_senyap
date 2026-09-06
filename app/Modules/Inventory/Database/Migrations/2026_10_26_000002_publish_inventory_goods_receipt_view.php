<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain K item B: penerimaan barang diterbitkan untuk buku hutang vendor.
 *
 * Konteks finance menyusun hutang usaha lintas empat rantai pengadaan
 * (pharmacy, inventory, kitchen, asset). Tanpa kontrak ini finance harus
 * menyentuh empat schema sekaligus — justru jenis ketergantungan yang
 * dijaga arsitektur ini.
 *
 * YANG DIPAPARKAN HANYA APA YANG KONTEKS INI SUNGGUH TAHU: barang apa
 * yang datang, dari siapa, kapan, dan senilai berapa. Nomor faktur,
 * status bayar, dan nilai terbayar TIDAK ikut, meski pharmacy kebetulan
 * punya kolomnya sejak domain D — hutang dan pelunasannya milik finance,
 * dan memaparkannya dari sini akan melahirkan dua sumber kebenaran yang
 * bisa berbeda tanpa ada yang menyadarinya.
 *
 * NILAI DIHITUNG DARI QUANTITY YANG DITERIMA x HARGA PADA BARIS PO,
 * bukan dari total_amount PO. Barang yang datang sebagian menimbulkan
 * hutang sebesar yang datang, bukan sebesar yang dipesan — memakai nilai
 * PO akan mencatat hutang lebih besar daripada yang sungguh terutang, dan
 * selisihnya baru ketahuan saat vendor menagih.
 */
return new class extends Migration
{
    private const S = 'inventory';

    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW ' . self::S . '.v_goods_receipt AS
            SELECT g.id,
                   g.receipt_number,
                   g.purchase_order_id,
                   p.po_number,
                   p.supplier_id,
                   s.name AS supplier_name,
                   g.received_at,
                   coalesce((
                       SELECT sum(i.quantity_received * pi.unit_price)
                         FROM ' . self::S . '.goods_receipt_items i
                         JOIN ' . self::S . '.purchase_order_items pi ON pi.id = i.purchase_order_item_id
                        WHERE i.goods_receipt_id = g.id
                   ), 0) AS nilai_terima
              FROM ' . self::S . '.goods_receipts g
              JOIN ' . self::S . '.purchase_orders p ON p.id = g.purchase_order_id
              LEFT JOIN ' . self::S . '.suppliers s ON s.id = p.supplier_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ' . self::S . '.v_goods_receipt');
    }
};
