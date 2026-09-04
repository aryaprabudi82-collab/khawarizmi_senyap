<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\StockReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Laporan Stok Farmasi — layar gabungan menaungi sisa_stok (gerbang
 * literal), darurat_stok, data_batch, kadaluarsa_batch,
 * riwayat_data_batch, obat_bhp_tidakbergerak,
 * stok_akhir_farmasi_pertanggal, sirkulasi_obat s/d sirkulasi_obat6.
 * Lihat catatan migrasi 2026_09_28_000001 untuk alasan konsolidasi.
 */
class StockReportController
{
    public function __construct(private readonly StockReportService $reports) {}

    public function index(Request $request): View
    {
        $lokasi = StockLocation::query()->where('is_active', true)->orderBy('name')->get();
        $lokasiId = $request->query('location_id') ? (int) $request->query('location_id') : null;
        $tanggal = $request->query('tanggal', now()->toDateString());

        return view('pharmacy::laporan-stok.index', [
            'lokasi' => $lokasi,
            'lokasiId' => $lokasiId,
            'tanggal' => $tanggal,
            'sisaStok' => $this->reports->currentStock($lokasiId),
            'daruratStok' => $this->reports->lowStock(),
            'batch' => $this->reports->batches($lokasiId),
            'kedaluwarsa' => $lokasiId ? $this->reports->expiring($lokasiId) : collect(),
            'tidakBergerak' => $this->reports->nonMoving(),
            'saldoPerTanggal' => $this->reports->balanceAsOf($tanggal, $lokasiId),
            'sirkulasi' => $this->reports->circulation([
                'location_id' => $lokasiId,
                'kind' => $request->query('kind'),
                'dari' => $request->query('dari'),
                'sampai' => $request->query('sampai'),
            ]),
        ]);
    }

    public function batchHistory(int $batchId): View
    {
        return view('pharmacy::laporan-stok.riwayat-batch', [
            'batchId' => $batchId,
            'riwayat' => $this->reports->batchHistory($batchId),
        ]);
    }
}
