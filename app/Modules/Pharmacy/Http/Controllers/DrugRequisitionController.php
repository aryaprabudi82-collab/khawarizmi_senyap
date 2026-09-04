<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugRequisition;
use App\Modules\Pharmacy\Services\DrugRequisitionService;
use App\Modules\Pharmacy\Services\OrganizationContext;
use App\Modules\Pharmacy\Services\PharmacyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** pengajuan_barang_medis — pola sama dengan Inventory\Http\Controllers\RequisitionController (non-medis). */
class DrugRequisitionController
{
    public function __construct(
        private readonly DrugRequisitionService $requisitions,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('pharmacy::pengajuan.index', [
            'pengajuan' => DrugRequisition::query()->with('items')->latest('created_at')->limit(50)->get(),
            'unit' => $this->organization->units(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['unit_id' => 'unit']);

        $unit = $this->organization->find((int) $data['unit_id']);

        if ($unit === null) {
            return back()->withInput()->with('galat', 'Unit tidak ditemukan.');
        }

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = $qty;
            }
        }

        try {
            $pengajuan = $this->requisitions->request((int) $data['unit_id'], $unit->name, $items, $data['notes'] ?? null, $request->user()->id);
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pengajuan {$pengajuan->requisition_number} tercatat.");
    }

    public function approve(Request $request, DrugRequisition $pengajuan): RedirectResponse
    {
        try {
            $this->requisitions->approve($pengajuan, $request->user()->id);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pengajuan {$pengajuan->requisition_number} disetujui.");
    }

    public function reject(Request $request, DrugRequisition $pengajuan): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']], [], ['rejection_reason' => 'alasan']);

        try {
            $this->requisitions->reject($pengajuan, $request->user()->id, $data['rejection_reason']);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pengajuan {$pengajuan->requisition_number} ditolak.");
    }
}
