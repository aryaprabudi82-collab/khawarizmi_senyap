<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\EnvironmentalMeasurement;
use App\Modules\Asset\Models\PestControlVisit;
use App\Modules\Asset\Services\EnvironmentalHealthService;
use App\Modules\Asset\Services\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnvironmentalHealthController
{
    public function __construct(
        private readonly EnvironmentalHealthService $kesling,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('asset::kesling.index', [
            'pengukuran' => EnvironmentalMeasurement::query()->latest('measured_on')->limit(50)->get(),
            'pestControl' => PestControlVisit::query()->latest('visited_on')->limit(20)->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function storeMeasurement(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:limbah-b3-cair,limbah-b3-padat,limbah-domestik,mutu-air-limbah,air-pdam,air-tanah'],
            'parameter' => ['nullable', 'string', 'max:50'],
            'measured_on' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['category' => 'kategori', 'measured_on' => 'tanggal ukur', 'quantity' => 'jumlah', 'unit' => 'satuan']);

        $this->kesling->recordMeasurement($data, $request->user());

        return back()->with('sukses', 'Pengukuran tercatat.');
    }

    public function storePestControl(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'visited_on' => ['required', 'date'],
            'location' => ['required', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer'],
            'findings' => ['required', 'string', 'max:1000'],
            'action_taken' => ['required', 'string', 'max:1000'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['visited_on' => 'tanggal kunjungan', 'location' => 'lokasi', 'unit_id' => 'unit', 'findings' => 'temuan', 'action_taken' => 'tindakan']);

        $this->kesling->recordPestControlVisit($data, $request->user());

        return back()->with('sukses', 'Kunjungan pest control tercatat.');
    }
}
