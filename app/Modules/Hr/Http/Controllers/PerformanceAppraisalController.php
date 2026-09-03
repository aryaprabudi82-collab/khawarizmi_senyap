<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PerformanceAppraisal;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\PerformanceAppraisalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** SKP — digerbangi permission tersendiri, lihat catatan migrasi hr. */
class PerformanceAppraisalController
{
    public function __construct(private readonly PerformanceAppraisalService $appraisals) {}

    public function index(): View
    {
        return view('hr::skp.index', [
            'pegawai' => Employee::query()->where('is_active', true)->orderBy('name')->get(),
            'penilaian' => PerformanceAppraisal::query()
                ->with('employee')
                ->orderByDesc('period')
                ->orderBy('employee_id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'period' => ['required', 'string', 'max:20'],
            'score' => ['required', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string'],
        ], [], ['employee_id' => 'pegawai', 'period' => 'periode', 'score' => 'skor']);

        $pegawai = Employee::query()->findOrFail($data['employee_id']);

        try {
            $this->appraisals->record($pegawai, $data, $request->user()->id);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Penilaian SKP {$pegawai->name} periode {$data['period']} tercatat.");
    }

    public function finalize(PerformanceAppraisal $penilaian): RedirectResponse
    {
        try {
            $this->appraisals->finalize($penilaian);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Penilaian SKP difinalisasi.');
    }
}
