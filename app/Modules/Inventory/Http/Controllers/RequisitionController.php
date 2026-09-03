<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Requisition;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\OrganizationContext;
use App\Modules\Inventory\Services\RequisitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RequisitionController
{
    public function __construct(
        private readonly RequisitionService $requisitions,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('inventory::permintaan.index', [
            'permintaan' => Requisition::query()->with('items.item')->latest('created_at')->limit(50)->get(),
            'unit' => $this->organization->units(),
            'barang' => Item::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'item_id' => ['required', 'array', 'min:1'],
            'item_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['unit_id' => 'unit']);

        $unit = $this->organization->find((int) $data['unit_id']);

        if ($unit === null) {
            return back()->withInput()->with('galat', 'Unit tidak ditemukan.');
        }

        $items = [];
        foreach ($data['item_id'] as $i => $itemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$itemId] = $qty;
            }
        }

        try {
            $permintaan = $this->requisitions->request((int) $data['unit_id'], $unit->name, $items, $data['notes'] ?? null, $request->user()->id);
        } catch (InventoryException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->requisition_number} diajukan.");
    }

    public function approve(Request $request, Requisition $permintaan): RedirectResponse
    {
        try {
            $this->requisitions->approve($permintaan, $request->user()->id);
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->requisition_number} disetujui.");
    }

    public function reject(Request $request, Requisition $permintaan): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']], [], ['rejection_reason' => 'alasan']);

        try {
            $this->requisitions->reject($permintaan, $request->user()->id, $data['rejection_reason']);
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->requisition_number} ditolak.");
    }

    public function fulfill(Request $request, Requisition $permintaan): RedirectResponse
    {
        try {
            $this->requisitions->fulfill($permintaan, $request->user());
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Barang untuk {$permintaan->requisition_number} sudah dikeluarkan.");
    }
}
