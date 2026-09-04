<?php

namespace App\Modules\Kitchen\Services;

use Illuminate\Support\Facades\DB;

/**
 * Layar gabungan "Rekap Dapur & Gizi" — menaungi 10 kode Khanza item D
 * yang semuanya laporan/browsing atas kitchen.requisitions/
 * purchase_orders/goods_receipts/supplier_returns/stock_movements yang
 * SUDAH ADA (bukan tabel baru): ringkasan_pengajuan_dapur,
 * ringkasan_pemesanan_dapur, dapur_ringkasan_pembelian,
 * ringkasan_penerimaan_dapur, ringkasan_stokkeluar_dapur,
 * ringkasan_returbeli_dapur, biaya_pengadaan_dapur,
 * dapur_stokkeluar_pertanggal, nilai_penerimaan_vendor_dapur_perbulan.
 * Digerbangi rekap_pengadaan_dapur (dikonfirmasi user, dipilih karena
 * paling generik mewakili "rekap pengadaan dapur" secara keseluruhan
 * — padanan langsung ipsrs_rekap_pengadaan domain E item D).
 *
 * Varian "_pertanggal" (dapur_stokkeluar_pertanggal) TIDAK menambah
 * method sendiri — method di bawah sudah menerima rentang tanggal,
 * tinggal set dari=sampai=satu hari yang sama (pola sama dengan
 * InventoryRecapService domain E item D).
 */
class KitchenRecapService
{
    /** ringkasan_pengajuan_dapur — jumlah & status permintaan unit dalam rentang tanggal. */
    public function permintaanRingkasan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $perStatus = DB::table('kitchen.requisitions')
            ->whereBetween('created_at', [$dari, $akhir])
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return ['per_status' => $perStatus, 'total' => (int) $perStatus->sum()];
    }

    /** ringkasan_pemesanan_dapur + dapur_ringkasan_pembelian + rekap_pengadaan_dapur — jumlah & nilai PO dalam rentang tanggal, per status. */
    public function pengadaanRingkasan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $perStatus = DB::table('kitchen.purchase_orders')
            ->whereBetween('created_at', [$dari, $akhir])
            ->selectRaw('status, count(*) as jumlah, sum(total_amount) as nilai')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'per_status' => $perStatus,
            'total_po' => (int) $perStatus->sum('jumlah'),
            'total_nilai' => (float) $perStatus->sum('nilai'),
        ];
    }

    /** ringkasan_penerimaan_dapur + nilai_penerimaan_vendor_dapur_perbulan — jumlah & nilai penerimaan per suplier dalam rentang tanggal. */
    public function penerimaanRingkasan(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('kitchen.goods_receipts as r')
            ->join('kitchen.purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
            ->join('kitchen.suppliers as s', 's.id', '=', 'po.supplier_id')
            ->join('kitchen.goods_receipt_items as ri', 'ri.goods_receipt_id', '=', 'r.id')
            ->leftJoin('kitchen.purchase_order_items as poi', 'poi.id', '=', 'ri.purchase_order_item_id')
            ->whereBetween('r.received_at', [$dari, $akhir])
            ->selectRaw('s.id as supplier_id, s.name as supplier_name,
                count(distinct r.id) as jumlah_penerimaan,
                sum(ri.quantity_received * coalesce(poi.unit_price, 0)) as nilai')
            ->groupBy('s.id', 's.name')
            ->orderBy('s.name')
            ->get();
    }

    /** ringkasan_stokkeluar_dapur + dapur_stokkeluar_pertanggal — total stok keluar per sumber dalam rentang tanggal. */
    public function stokKeluarRingkasan(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('kitchen.stock_movements')
            ->where('kind', 'keluar')
            ->whereBetween('moved_at', [$dari, $akhir])
            ->selectRaw('source, count(*) as jumlah_transaksi, sum(abs(quantity)) as total_keluar')
            ->groupBy('source')
            ->orderByDesc('total_keluar')
            ->get();
    }

    /** ringkasan_returbeli_dapur — jumlah & baris retur ke suplier dalam rentang tanggal, per status. */
    public function returRingkasan(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('kitchen.supplier_returns')
            ->whereBetween('returned_at', [$dari, $akhir])
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->get();
    }

    /** biaya_pengadaan_dapur — total nilai barang diterima pada satu tanggal (biaya pengadaan hari itu). */
    public function pengeluaranHarian(string $tanggal): float
    {
        $akhir = $tanggal . ' 23:59:59';

        return (float) DB::table('kitchen.goods_receipts as r')
            ->join('kitchen.goods_receipt_items as ri', 'ri.goods_receipt_id', '=', 'r.id')
            ->leftJoin('kitchen.purchase_order_items as poi', 'poi.id', '=', 'ri.purchase_order_item_id')
            ->whereBetween('r.received_at', [$tanggal, $akhir])
            ->sum(DB::raw('ri.quantity_received * coalesce(poi.unit_price, 0)'));
    }
}
