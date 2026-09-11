<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\AncillaryReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar penunjang, gizi & sasaran (domain J item E).
 *
 * Menaungi sisa kode domain J di luar HAIs — yang itu punya layarnya
 * sendiri di konteks quality karena bukan cuma laporan, melainkan juga
 * tempat mencatat kejadian infeksi.
 */
class AncillaryReportController
{
    public function __construct(private readonly AncillaryReportService $penunjang) {}

    public function index(Request $request): View
    {
        $tahun = (int) $request->query('tahun', now()->year);
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $kelompok = $request->query('kelompok', 'bulanan');

        if (! in_array($kelompok, ['harian', 'bulanan', 'bangsal'], true)) {
            $kelompok = 'bulanan';
        }

        return view('reporting::penunjang.index', [
            'tahun' => $tahun,
            'dari' => $dari,
            'sampai' => $sampai,
            'kelompok' => $kelompok,

            'lab' => $this->penunjang->ancillaryYearly('lab', $tahun),
            'radiologi' => $this->penunjang->ancillaryYearly('radiologi', $tahun),
            'perujukLab' => $this->penunjang->ancillaryReferrers('lab', $tahun),
            'perujukRadiologi' => $this->penunjang->ancillaryReferrers('radiologi', $tahun),
            'operasi' => $this->penunjang->surgeryMonthly($tahun),
            'diet' => $this->penunjang->dietRecap($dari, $sampai),
            'skrining' => $this->penunjang->respiratoryScreening($tahun),
            'klasifikasi' => $this->penunjang->inpatientClass($kelompok, $dari, $sampai),
            'sasaran' => $this->penunjang->ageTargets($dari, $sampai),
            'keselamatanBedah' => $this->penunjang->surgicalSafetyCompliance($dari, $sampai),
            'penolakan' => $this->penunjang->advisoryRefusalYearly($tahun),
        ]);
    }
}
