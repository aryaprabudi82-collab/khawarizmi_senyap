<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Models\DailyRevenueSummary;
use App\Modules\Reporting\Models\DailyVisitSummary;
use App\Modules\Reporting\Models\DiagnosisFrequency;
use App\Modules\Reporting\Services\ReportingSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController
{
    public function __construct(private readonly ReportingSyncService $syncService) {}

    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();

        $kunjungan = DailyVisitSummary::query()->whereDate('report_date', $tanggal)->orderBy('unit_name')->get();

        return view('reporting::dashboard.index', [
            'tanggal' => $tanggal,
            'kunjungan' => $kunjungan,
            'totalKunjungan' => $kunjungan->sum('visit_count'),
            'diagnosis' => DiagnosisFrequency::query()->whereDate('report_date', $tanggal)->orderByDesc('occurrence_count')->limit(10)->get(),
            'pendapatan' => DailyRevenueSummary::query()->whereDate('report_date', $tanggal)->orderBy('payer_kind')->get(),
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $tanggal = CarbonImmutable::parse($request->input('tanggal', now()->toDateString()))->startOfDay();

        $hasil = $this->syncService->syncDay($tanggal);

        return redirect()->route('reporting.dashboard', ['tanggal' => $tanggal->toDateString()])
            ->with('sukses', "Disinkronkan: {$hasil['kunjungan']} kelompok kunjungan, {$hasil['diagnosis']} kode diagnosis, {$hasil['pendapatan']} kelompok pendapatan.");
    }
}
