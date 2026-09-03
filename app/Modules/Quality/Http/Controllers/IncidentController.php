<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Models\IncidentReport;
use App\Modules\Quality\Services\IncidentReportService;
use App\Modules\Quality\Services\OrganizationContext;
use App\Modules\Quality\Services\QualityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncidentController
{
    public function __construct(
        private readonly IncidentReportService $incidents,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('quality::insiden.index', [
            'insiden' => IncidentReport::query()->latest('occurred_at')->limit(50)->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'incident_type' => ['required', 'in:kpc,knc,ktc,ktd,sentinel'],
            'severity_band' => ['required', 'in:biru,hijau,kuning,merah'],
            'occurred_at' => ['required', 'date'],
            'unit_id' => ['nullable', 'integer'],
            'location_detail' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'immediate_action' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'incident_type' => 'jenis insiden', 'severity_band' => 'tingkat dampak', 'occurred_at' => 'waktu kejadian',
            'unit_id' => 'unit', 'description' => 'kronologi',
        ]);

        $laporan = $this->incidents->report($data, $request->user()->id);

        return back()->with('sukses', "Insiden {$laporan->report_number} tercatat.");
    }

    public function review(Request $request, IncidentReport $insiden): RedirectResponse
    {
        try {
            $this->incidents->review($insiden, $request->user()->id);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Insiden {$insiden->report_number} ditinjau.");
    }

    public function close(Request $request, IncidentReport $insiden): RedirectResponse
    {
        $data = $request->validate([
            'root_cause' => ['required', 'string', 'max:2000'],
            'corrective_action' => ['required', 'string', 'max:2000'],
        ], [], ['root_cause' => 'akar masalah', 'corrective_action' => 'tindakan korektif']);

        try {
            $this->incidents->close($insiden, $data['root_cause'], $data['corrective_action']);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Insiden {$insiden->report_number} ditutup.");
    }
}
