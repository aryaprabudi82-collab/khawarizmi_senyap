<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Models\K3Incident;
use App\Modules\Quality\Services\HrContext;
use App\Modules\Quality\Services\K3IncidentService;
use App\Modules\Quality\Services\QualityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class K3IncidentController
{
    public function __construct(
        private readonly K3IncidentService $incidents,
        private readonly HrContext $hr,
    ) {}

    public function index(): View
    {
        return view('quality::k3.index', [
            'insiden' => K3Incident::query()->latest('occurred_at')->limit(50)->get(),
            'pegawai' => $this->hr->employees(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'occurred_at' => ['required', 'date'],
            'location' => ['required', 'string', 'max:150'],
            'body_part' => ['required', 'string', 'max:100'],
            'injury_impact' => ['required', 'string', 'max:100'],
            'injury_type' => ['required', 'string', 'max:100'],
            'job_type' => ['nullable', 'string', 'max:100'],
            'cause' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
        ], [], [
            'employee_id' => 'pegawai', 'occurred_at' => 'waktu kejadian', 'location' => 'lokasi',
            'body_part' => 'bagian tubuh', 'injury_impact' => 'dampak cidera', 'injury_type' => 'jenis cidera',
            'job_type' => 'jenis pekerjaan', 'cause' => 'penyebab', 'description' => 'kronologi',
        ]);

        $insiden = $this->incidents->report($data, $request->user()->id);

        return back()->with('sukses', "Insiden K3 {$insiden->incident_number} tercatat.");
    }

    public function review(Request $request, K3Incident $insiden): RedirectResponse
    {
        try {
            $this->incidents->review($insiden, $request->user()->id);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Insiden {$insiden->incident_number} ditinjau.");
    }

    public function close(Request $request, K3Incident $insiden): RedirectResponse
    {
        $data = $request->validate(['corrective_action' => ['required', 'string', 'max:2000']], [], ['corrective_action' => 'tindakan korektif']);

        try {
            $this->incidents->close($insiden, $data['corrective_action']);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Insiden {$insiden->incident_number} ditutup.");
    }
}
