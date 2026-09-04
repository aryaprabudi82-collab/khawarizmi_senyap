<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Modules\Kitchen\Services\KitchenRecapService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rekap Dapur & Gizi — layar gabungan menaungi rekap_pengadaan_dapur
 * (gerbang literal) + 9 kode lain. Lihat catatan migrasi
 * 2026_10_07_000001.
 */
class KitchenRecapController
{
    public function __construct(private readonly KitchenRecapService $recap) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $tanggalHarian = $request->query('tanggal', now()->toDateString());

        return view('kitchen::rekap.index', [
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
