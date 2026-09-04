<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\PatientDrugReturn;
use App\Modules\Pharmacy\Services\PatientDrugReturnService;
use App\Modules\Pharmacy\Services\PharmacyException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** retur_obat_ranap. */
class PatientDrugReturnController
{
    public function __construct(private readonly PatientDrugReturnService $returns) {}

    public function index(): View
    {
        return view('pharmacy::retur-ranap.index', [
            'retur' => PatientDrugReturn::query()->with('items')->latest('created_at')->limit(50)->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function searchRegistration(Request $request): JsonResponse
    {
        $nomor = (string) $request->query('nomor', '');
        $kunjungan = $nomor !== '' ? $this->returns->findRegistrationByNumber($nomor) : null;

        return response()->json(['kunjungan' => $kunjungan]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['registration_id' => 'kunjungan']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = $qty;
            }
        }

        try {
            $retur = $this->returns->return((int) $data['registration_id'], $items, $data['notes'] ?? null, $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Retur {$retur->return_number} tercatat.");
    }
}
