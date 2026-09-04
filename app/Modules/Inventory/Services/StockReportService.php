<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Layar gabungan "Riwayat & Sirkulasi Barang" — menaungi 3 kode Khanza
 * yang semuanya laporan/browsing atas inventory.stock_movements yang
 * sudah ada sejak migrasi awal (bukan tabel baru): ipsrs_riwayat_barang
 * (dipakai sebagai gerbang wakil), sirkulasi_non_medis, sirkulasi_non_medis2
 * (2 varian menu Khanza — di sini satu buku besar terfilter dan satu
 * rekap bulanan per barang menutupi keduanya, sama pola dengan
 * sirkulasi_obat 6-varian farmasi yang cukup satu method terfilter).
 */
class StockReportService
{
    /** ipsrs_riwayat_barang — buku besar satu barang, terbaru dulu. */
    public function riwayat(int $itemId): Collection
    {
        return DB::table('inventory.stock_movements')
            ->where('item_id', $itemId)
            ->orderByDesc('moved_at')
            ->get();
    }

    /**
     * sirkulasi_non_medis — buku besar pergerakan terfilter, lintas
     * barang.
     *
     * @param array{item_id?: int, kind?: string, dari?: string, sampai?: string} $filter
     */
    public function sirkulasi(array $filter = []): Collection
    {
        $query = DB::table('inventory.stock_movements as m')
            ->join('inventory.items as i', 'i.id', '=', 'm.item_id')
            ->select('m.*', 'i.name as item_name', 'i.unit_of_measure')
            ->orderByDesc('m.moved_at')
            ->limit(200);

        if (! empty($filter['item_id'])) {
            $query->where('m.item_id', $filter['item_id']);
        }
        if (! empty($filter['kind'])) {
            $query->where('m.kind', $filter['kind']);
        }
        if (! empty($filter['dari'])) {
            $query->where('m.moved_at', '>=', $filter['dari']);
        }
        if (! empty($filter['sampai'])) {
            $query->where('m.moved_at', '<=', $filter['sampai'] . ' 23:59:59');
        }

        return $query->get();
    }

    /** sirkulasi_non_medis2 — rekap bulanan per barang: total masuk/keluar/opname dan saldo akhir bulan itu. */
    public function sirkulasiBulanan(string $bulan): Collection
    {
        $awal = $bulan . '-01';
        $akhir = date('Y-m-t', strtotime($awal)) . ' 23:59:59';

        return DB::table('inventory.stock_movements as m')
            ->join('inventory.items as i', 'i.id', '=', 'm.item_id')
            ->whereBetween('m.moved_at', [$awal, $akhir])
            ->selectRaw("
                m.item_id, i.name as item_name, i.unit_of_measure,
                sum(case when m.kind = 'masuk' then m.quantity else 0 end) as total_masuk,
                sum(case when m.kind = 'keluar' then abs(m.quantity) else 0 end) as total_keluar,
                sum(case when m.kind = 'opname' then m.quantity else 0 end) as total_koreksi_opname
            ")
            ->groupBy('m.item_id', 'i.name', 'i.unit_of_measure')
            ->orderBy('i.name')
            ->get();
    }
}
