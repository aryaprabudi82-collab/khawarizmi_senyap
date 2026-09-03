<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Models\IcraAssessment;
use App\Modules\Quality\Services\IcraService;
use App\Modules\Quality\Services\OrganizationContext;
use App\Modules\Quality\Services\QualityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IcraController
{
    public function __construct(
        private readonly IcraService $icra,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('quality::icra.index', [
            'kajian' => IcraAssessment::query()->latest('assessed_at')->limit(50)->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_name' => ['required', 'string', 'max:200'],
            'project_type' => ['required', 'string', 'max:50'],
            'location' => ['required', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer'],
            'infection_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'fire_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'safety_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'utility_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'risk_class' => ['required', 'in:I,II,III,IV'],
            'required_precautions' => ['nullable', 'string', 'max:2000'],
            'control_measures' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date'],
        ], [], [
            'project_name' => 'nama proyek', 'project_type' => 'jenis aktivitas', 'location' => 'lokasi',
            'infection_risk_level' => 'risiko infeksi', 'fire_risk_level' => 'risiko kebakaran',
            'safety_risk_level' => 'risiko keselamatan', 'utility_risk_level' => 'risiko utilitas',
            'risk_class' => 'kelas risiko',
        ]);

        $kajian = $this->icra->assess($data, $request->user()->id);

        return back()->with('sukses', "Kajian {$kajian->assessment_number} tersimpan.");
    }

    public function complete(IcraAssessment $kajian): RedirectResponse
    {
        try {
            $this->icra->complete($kajian);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kajian {$kajian->assessment_number} ditandai selesai.");
    }

    public function cancel(IcraAssessment $kajian): RedirectResponse
    {
        try {
            $this->icra->cancel($kajian);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kajian {$kajian->assessment_number} dibatalkan.");
    }
}
