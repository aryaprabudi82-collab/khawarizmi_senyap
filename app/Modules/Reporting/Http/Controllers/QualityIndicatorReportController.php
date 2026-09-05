<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\QualityIndicatorReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar indikator mutu & lama pelayanan (domain J item D).
 *
 * Satu layar berpenyaring untuk 13 kode Khanza, pola yang sama seperti
 * item A/B/C — semuanya potongan berbeda dari pertanyaan yang sama:
 * berapa lama pasien menunggu, dan seberapa efisien tempat tidurnya.
 */
class QualityIndicatorReportController
{
    public function __construct(private readonly QualityIndicatorReportService $mutu) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $unit = $request->query('unit') ?: null;
        $kategori = $request->query('kategori', 'lab');

        if (! in_array($kategori, ['lab', 'radiologi', 'pa'], true)) {
            $kategori = 'lab';
        }

        return view('reporting::mutu.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'unit' => $unit,
            'kategori' => $kategori,
            'daftarUnit' => $this->mutu->units($dari, $sampai),

            'efisiensi' => $this->mutu->bedEfficiency($dari, $sampai),
            'ralan' => $this->mutu->outpatientDuration($dari, $sampai, $unit),
            'perUnit' => $this->mutu->outpatientByUnit($dari, $sampai, $unit),
            'spm' => $this->mutu->waitingTimeCompliance($dari, $sampai, $unit),
            'apotek' => $this->mutu->pharmacyDuration($dari, $sampai),
            'penunjang' => $this->mutu->orderDuration($kategori, $dari, $sampai),
            'operasi' => $this->mutu->operationDuration($dari, $sampai),
            'cssd' => $this->mutu->cssdDuration($dari, $sampai),
        ]);
    }
}
