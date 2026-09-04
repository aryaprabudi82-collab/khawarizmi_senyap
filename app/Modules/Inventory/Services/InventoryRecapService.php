<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/**
 * Layar gabungan "Rekap Logistik Non-Medis" — menaungi 14 kode Khanza
 * item D yang semuanya laporan/browsing atas inventory.requisitions/
 * purchase_orders/goods_receipts/supplier_returns/stock_movements yang
 * SUDAH ADA (bukan tabel baru): rekap_permintaan_non_medis,
 * ringkasan_pengajuan_nonmedis, ringkasan_pemesanan_nonmedis,
 * ringkasan_pengadaan_nonmedis, ringkasan_penerimaan_nonmedis,
 * ringkasan_stokkeluar_nonmedis, ringkasan_returbeli_nonmedis,
 * ipsrs_pengeluaran_harian, ipsrs_rekap_pengadaan, ipsrs_rekap_stok_keluar,
 * ipsrs_pengadaan_pertanggal, ipsrs_stokkeluar_pertanggal,
 * rekap_pemesanan_non_medis, nilai_penerimaan_vendor_nonmedis_perbulan.
 * Digerbangi ipsrs_rekap_pengadaan (dikonfirmasi user, dipilih karena
 * paling generik mewakili "rekap IPSRS" secara keseluruhan).
 *
 * Varian "_pertanggal" (ipsrs_pengadaan_pertanggal, ipsrs_stokkeluar_pertanggal)
 * TIDAK menambah method sendiri — method di bawah sudah menerima rentang
 * tanggal, tinggal set dari=sampai=satu hari yang sama (pola sama
 * dengan PharmacyRecapService item 6).
 */
class InventoryRecapService
{
    /** rekap_permintaan_non_medis + ringkasan_pengajuan_nonmedis — jumlah & status permintaan unit dalam rentang tanggal. */
    public function permintaanRingkasan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $perStatus = DB::table('inventory.requisitions')
            ->whereBetween('created_at', [$dari, $akhir])
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return ['per_status' => $perStatus, 'total' => (int) $perStatus->sum()];
    }

    /** ringkasan_pemesanan_nonmedis + ringkasan_pengadaan_nonmedis + ipsrs_rekap_pengadaan + rekap_pemesanan_non_medis + ipsrs_pengadaan_pertanggal — jumlah & nilai PO dalam rentang tanggal, per status. */
    public function pengadaanRingkasan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $perStatus = DB::table('inventory.purchase_orders')
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

    /** ringkasan_penerimaan_nonmedis + nilai_penerimaan_vendor_nonmedis_perbulan — jumlah & nilai penerimaan per suplier dalam rentang tanggal. */
    public function penerimaanRingkasan(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('inventory.goods_receipts as r')
            ->join('inventory.purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
            ->join('inventory.suppliers as s', 's.id', '=', 'po.supplier_id')
            ->join('inventory.goods_receipt_items as ri', 'ri.goods_receipt_id', '=', 'r.id')
            ->leftJoin('inventory.purchase_order_items as poi', 'poi.id', '=', 'ri.purchase_order_item_id')
            ->whereBetween('r.received_at', [$dari, $akhir])
            ->selectRaw('s.id as supplier_id, s.name as supplier_name,
                count(distinct r.id) as jumlah_penerimaan,
                sum(ri.quantity_received * coalesce(poi.unit_price, 0)) as nilai')
            ->groupBy('s.id', 's.name')
            ->orderBy('s.name')
            ->get();
    }

    /** ringkasan_stokkeluar_nonmedis + ipsrs_rekap_stok_keluar + ipsrs_stokkeluar_pertanggal — total stok keluar per sumber dalam rentang tanggal. */
    public function stokKeluarRingkasan(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('inventory.stock_movements')
            ->where('kind', 'keluar')
            ->whereBetween('moved_at', [$dari, $akhir])
            ->selectRaw('source, count(*) as jumlah_transaksi, sum(abs(quantity)) as total_keluar')
            ->groupBy('source')
            ->orderByDesc('total_keluar')
            ->get();
    }

    /** ringkasan_returbeli_nonmedis — jumlah & baris retur ke suplier dalam rentang tanggal, per status. */
    public function returRingkasan(string $dari, string $sampai): \Illuminate\Support\Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('inventory.supplier_returns')
            ->whereBetween('returned_at', [$dari, $akhir])
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->get();
    }

    /** ipsrs_pengeluaran_harian — total nilai barang diterima pada satu tanggal (biaya pengadaan hari itu). */
    public function pengeluaranHarian(string $tanggal): float
    {
        $akhir = $tanggal . ' 23:59:59';

        return (float) DB::table('inventory.goods_receipts as r')
            ->join('inventory.goods_receipt_items as ri', 'ri.goods_receipt_id', '=', 'r.id')
            ->leftJoin('inventory.purchase_order_items as poi', 'poi.id', '=', 'ri.purchase_order_item_id')
            ->whereBetween('r.received_at', [$tanggal, $akhir])
            ->sum(DB::raw('ri.quantity_received * coalesce(poi.unit_price, 0)'));
    }
}
