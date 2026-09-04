<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Modules\Kitchen\Models\Item;
use App\Modules\Kitchen\Models\PurchaseOrder;
use App\Modules\Kitchen\Models\Requisition;
use App\Modules\Kitchen\Models\Supplier;
use App\Modules\Kitchen\Services\KitchenException;
use App\Modules\Kitchen\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** dapur_pembelian. Method print() melayani surat_pemesanan_dapur — gerbang terpisah, data sama, lihat catatan migrasi. */
class PurchaseOrderController
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(): View
    {
        return view('kitchen::po.index', [
            'po' => PurchaseOrder::query()->with('supplier')->latest('created_at')->limit(50)->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'barang' => Item::query()->where('is_active', true)->orderBy('name')->get(),
            'pengajuanTerbuka' => Requisition::query()->where('status', 'disetujui')->orderBy('requisition_number')->get(),
        ]);
    }

    public function show(PurchaseOrder $po): View
    {
        return view('kitchen::po.show', ['po' => $po->load(['items', 'supplier', 'receipts'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'requisition_id' => ['nullable', 'integer'],
            'item_id' => ['required', 'array', 'min:1'],
            'item_id.*' => ['integer'],
            'unit_of_measure' => ['required', 'array'],
            'unit_of_measure.*' => ['nullable', 'string', 'max:20'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['required', 'array'],
            'unit_price.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'suplier']);

        $items = [];
        foreach ($data['item_id'] as $i => $itemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$itemId] = [
                    'quantity' => $qty,
                    'unit_of_measure' => $data['unit_of_measure'][$i] ?? '',
                    'unit_price' => (float) ($data['unit_price'][$i] ?? 0),
                ];
            }
        }

        $requisition = ! empty($data['requisition_id']) ? Requisition::query()->find($data['requisition_id']) : null;

        try {
            $po = $this->orders->create((int) $data['supplier_id'], $items, $request->user()->id, $requisition);
        } catch (KitchenException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('kitchen.po.show', $po)->with('sukses', "PO {$po->po_number} dibuat.");
    }

    public function submit(PurchaseOrder $po): RedirectResponse
    {
        try {
            $this->orders->submit($po);
        } catch (KitchenException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "PO {$po->po_number} dikirim ke suplier.");
    }

    public function cancel(PurchaseOrder $po): RedirectResponse
    {
        try {
            $this->orders->cancel($po);
        } catch (KitchenException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "PO {$po->po_number} dibatalkan.");
    }

    public function print(PurchaseOrder $po): View
    {
        return view('kitchen::po.cetak', ['po' => $po->load(['items', 'supplier'])]);
    }
}
