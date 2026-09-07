<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\OrganizationContext;
use App\Modules\Reporting\Services\StatutoryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Laporan RL Kemenkes (domain J item C) — 11 kode dalam satu layar,
 * digerbangi rl4a sebagai kode yang paling mewakili.
 *
 * Layar ini sengaja menyatakan batasnya sendiri: yang disajikan adalah
 * angka yang mendasari tiap RL, bukan formulir siap kirim.
 */
class StatutoryReportController
{
    public function __construct(
        private readonly StatutoryReportService $rl,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(Request $request): View
    {
        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->toDateString();
        $sampai = Carbon::parse($request->query('sampai', now()->toDateString()))->toDateString();

        $unitGigi = $request->query('unit_gigi', 'Poliklinik Gigi dan Mulut');
        $unitObgyn = $request->query('unit_obgyn', 'Poliklinik Kebidanan dan Kandungan');

        return view('reporting::rl.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'unit' => $this->organization->activeUnits(),
            'unitGigi' => $unitGigi,
            'unitObgyn' => $unitObgyn,

            'tempatTidur' => $this->rl->bedAvailability(),
            'gawatDarurat' => $this->rl->emergencyActivity($dari, $sampai),
            'gigi' => $this->rl->unitActivity($unitGigi, $dari, $sampai),
            'kebidanan' => $this->rl->unitActivity($unitObgyn, $dari, $sampai),
            'pembedahan' => $this->rl->surgeryActivity($dari, $sampai),
            'radiologi' => $this->rl->supportActivity('radiologi', $dari, $sampai),
            'laboratorium' => $this->rl->supportActivity('lab', $dari, $sampai),

            'morbiditasRanap' => $this->rl->morbidity('ranap', $dari, $sampai),
            'morbiditasRalan' => $this->rl->morbidity('ralan', $dari, $sampai),
            'sebabRanap' => $this->rl->morbidityByCause('ranap', $dari, $sampai),
            'sebabRalan' => $this->rl->morbidityByCause('ralan', $dari, $sampai),

            'pakaiDtd' => $this->rl->usingDtd(),
            'belumDtd' => $this->rl->unclassifiedDtdCount(),
        ]);
    }
}
