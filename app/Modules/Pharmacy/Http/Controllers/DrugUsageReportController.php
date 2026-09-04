<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Services\DrugUsageReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menaungi pengguna_obat_resep, obat_per_resep, obat10_terbanyak_poli, rekap_obat_poli, rekap_obat_pasien, ringkasan_biaya_obat_pasien_pertanggal — lihat catatan DrugUsageReportService. */
class DrugUsageReportController
{
    public function __construct(private readonly DrugUsageReportService $reports) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $unit = $request->query('unit');

        return view('pharmacy::laporan-obat.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'unitFilter' => $unit,
            'perPasien' => $this->reports->byPatient($dari, $sampai),
            'perObat' => $this->reports->byDrug($dari, $sampai),
            'perDokter' => $this->reports->byPrescriber($dari, $sampai),
            'top10' => $this->reports->top10($dari, $sampai, $unit),
            'perUnit' => $this->reports->byUnit($dari, $sampai),
            'biayaPerTanggal' => $this->reports->biayaPerTanggal($dari, $sampai),
        ]);
    }
}
