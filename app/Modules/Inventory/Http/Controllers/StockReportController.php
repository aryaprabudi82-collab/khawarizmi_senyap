<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Riwayat & Sirkulasi Barang Non-Medis — layar gabungan menaungi
 * ipsrs_riwayat_barang (gerbang literal), sirkulasi_non_medis,
 * sirkulasi_non_medis2. Lihat catatan migrasi 2026_10_02_000001.
 */
class StockReportController
{
    public function __construct(private readonly StockReportService $reports) {}

    public function index(Request $request): View
    {
        $itemId = $request->query('item_id') ? (int) $request->query('item_id') : null;
        $bulan = $request->query('bulan', now()->format('Y-m'));

        return view('inventory::laporan.index', [
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
