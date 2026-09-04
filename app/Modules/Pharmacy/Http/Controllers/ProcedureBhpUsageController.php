<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\ProcedureBhpUsage;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\ProcedureBhpUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** penggunaan_bhp_ok. */
class ProcedureBhpUsageController
{
    public function __construct(private readonly ProcedureBhpUsageService $usages) {}

    public function index(): View
    {
        return view('pharmacy::bhp-ok.index', [
            'penggunaan' => ProcedureBhpUsage::query()->with('items')->latest('used_at')->limit(50)->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room' => ['required', Rule::in(['OK', 'VK'])],
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_mrn' => ['nullable', 'string', 'max:20'],
            'operation_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['room' => 'ruangan', 'patient_name' => 'nama pasien']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = $qty;
            }
        }

        $header = collect($data)->except(['drug_id', 'quantity'])->all();

        try {
            $penggunaan = $this->usages->record($header, $items, $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Penggunaan BHP {$penggunaan->usage_number} tercatat.");
    }
}
