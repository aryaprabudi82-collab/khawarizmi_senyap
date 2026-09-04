<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Services\PharmacyRecapService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menaungi keuntungan_penjualan, keuntungan_beri_obat, keuntungan_beri_obat_nonpiutang, dan 6 kode ringkasan_* — lihat catatan PharmacyRecapService. */
class PharmacyRecapController
{
    public function __construct(private readonly PharmacyRecapService $recap) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        return view('pharmacy::rekap.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'penjualan' => $this->recap->penjualanRingkasan($dari, $sampai),
            'beriObat' => $this->recap->beriObatKeuntungan($dari, $sampai),
            'stokKeluar' => $this->recap->stokKeluarRingkasan($dari, $sampai),
            'hibah' => $this->recap->hibahRingkasan($dari, $sampai),
        ]);
    }
}
