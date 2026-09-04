<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\ExternalPrescription;
use App\Modules\Pharmacy\Services\ExternalPrescriptionService;
use App\Modules\Pharmacy\Services\PharmacyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** resep_luar. */
class ExternalPrescriptionController
{
    public function __construct(private readonly ExternalPrescriptionService $prescriptions) {}

    public function index(): View
    {
        return view('pharmacy::resep-luar.index', [
            'resep' => ExternalPrescription::query()->with('items')->latest('created_at')->limit(50)->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_identity_number' => ['nullable', 'string', 'max:40'],
            'prescriber_name' => ['required', 'string', 'max:150'],
            'prescriber_license' => ['nullable', 'string', 'max:60'],
            'issued_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['required', 'array'],
            'unit_price.*' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'patient_name' => 'nama pasien', 'prescriber_name' => 'nama dokter penulis', 'issued_date' => 'tanggal resep',
        ]);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = ['quantity' => $qty, 'unit_price' => (float) ($data['unit_price'][$i] ?? 0)];
            }
        }

        $header = collect($data)->except(['drug_id', 'quantity', 'unit_price'])->all();

        try {
            $resep = $this->prescriptions->receive($header, $items, $request->user()->id);
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Resep luar {$resep->prescription_number} diterima.");
    }

    public function dispense(Request $request, ExternalPrescription $resep): RedirectResponse
    {
        try {
            $this->prescriptions->dispense($resep, $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Resep luar {$resep->prescription_number} diserahkan.");
    }

    public function cancel(ExternalPrescription $resep): RedirectResponse
    {
        try {
            $this->prescriptions->cancel($resep);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Resep luar {$resep->prescription_number} dibatalkan.");
    }
}
