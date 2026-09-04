<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Services\K3IncidentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class K3RecapController
{
    public function __construct(private readonly K3IncidentService $incidents) {}

    public function index(Request $request): View
    {
        $tahun = (int) $request->query('tahun', now()->year);

        return view('quality::k3.rekap', [
            'tahun' => $tahun,
            'rekap' => $this->incidents->yearlyRecap($tahun),
        ]);
    }
}
