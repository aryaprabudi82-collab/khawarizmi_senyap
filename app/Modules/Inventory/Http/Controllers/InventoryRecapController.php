<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Services\InventoryRecapService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rekap Logistik Non-Medis — layar gabungan menaungi ipsrs_rekap_pengadaan
 * (gerbang literal) + 13 kode lain. Lihat catatan migrasi
 * 2026_10_03_000001.
 */
class InventoryRecapController
{
    public function __construct(private readonly InventoryRecapService $recap) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $tanggalHarian = $request->query('tanggal', now()->toDateString());

        return view('inventory::rekap.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'tanggalHarian' => $tanggalHarian,
            'permintaan' => $this->recap->permintaanRingkasan($dari, $sampai),
            'pengadaan' => $this->recap->pengadaanRingkasan($dari, $sampai),
            'penerimaan' => $this->recap->penerimaanRingkasan($dari, $sampai),
            'stokKeluar' => $this->recap->stokKeluarRingkasan($dari, $sampai),
            'retur' => $this->recap->returRingkasan($dari, $sampai),
            'pengeluaranHarian' => $this->recap->pengeluaranHarian($tanggalHarian),
        ]);
    }
}
