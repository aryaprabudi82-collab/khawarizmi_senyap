<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Modules\Kitchen\Models\Item;
use App\Modules\Kitchen\Services\StockReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Riwayat & Sirkulasi Barang Dapur — layar gabungan menaungi
 * dapur_riwayat_barang (gerbang literal), sirkulasi_dapur,
 * sirkulasi_dapur2. Lihat catatan migrasi 2026_10_06_000001.
 */
class StockReportController
{
    public function __construct(private readonly StockReportService $reports) {}

    public function index(Request $request): View
    {
        $itemId = $request->query('item_id') ? (int) $request->query('item_id') : null;
        $bulan = $request->query('bulan', now()->format('Y-m'));

        return view('kitchen::laporan.index', [
            'barang' => Item::query()->where('is_active', true)->orderBy('name')->get(),
            'itemId' => $itemId,
            'bulan' => $bulan,
            'riwayat' => $itemId ? $this->reports->riwayat($itemId) : collect(),
            'sirkulasi' => $this->reports->sirkulasi([
                'item_id' => $itemId,
                'kind' => $request->query('kind'),
                'dari' => $request->query('dari'),
                'sampai' => $request->query('sampai'),
            ]),
            'sirkulasiBulanan' => $this->reports->sirkulasiBulanan($bulan),
        ]);
    }
}
