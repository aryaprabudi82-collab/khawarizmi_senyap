<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\PatientConsent;
use App\Modules\Correspondence\Services\ConsentService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsentController
{
    public function __construct(private readonly ConsentService $consents) {}

    public function index(): View
    {
        return view('correspondence::persetujuan.index', [
            'persetujuan' => PatientConsent::query()->latest('signed_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'consent_type' => ['required', 'in:tindakan,penolakan-anjuran-medis,resusitasi,umum'],
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'procedure_description' => ['required', 'string', 'max:2000'],
            'decision' => ['required', 'in:setuju,menolak'],
            'witness_name' => ['nullable', 'string', 'max:150'],
        ], [], [
            'consent_type' => 'jenis persetujuan', 'patient_name' => 'nama pasien',
            'procedure_description' => 'uraian tindakan', 'decision' => 'keputusan', 'witness_name' => 'nama saksi',
        ]);

        $persetujuan = $this->consents->issue($data, $request->user()->id);

        return back()->with('sukses', "Persetujuan {$persetujuan->consent_number} tersimpan.");
    }

    public function cancel(PatientConsent $persetujuan): RedirectResponse
    {
        try {
            $this->consents->cancel($persetujuan);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Persetujuan {$persetujuan->consent_number} dibatalkan.");
    }

    public function print(PatientConsent $persetujuan): View
    {
        return view('correspondence::persetujuan.cetak', ['persetujuan' => $persetujuan]);
    }
}
