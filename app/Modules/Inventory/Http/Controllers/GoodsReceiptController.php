<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** penerimaan_non_medis dan verifikasi_penerimaan_logistik. */
class GoodsReceiptController
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(): View
    {
        return view('inventory::penerimaan.index', [
            'penerimaan' => GoodsReceipt::query()->with(['purchaseOrder.supplier', 'items'])->latest('received_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request, PurchaseOrder $po): RedirectResponse
    {
        $data = $request->validate([
            'purchase_order_item_id' => ['required', 'array', 'min:1'],
            'purchase_order_item_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $items = [];
        foreach ($data['purchase_order_item_id'] as $i => $poItemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $baris = $po->items()->whereKey($poItemId)->firstOrFail();

            $items[] = [
                'purchase_order_item_id' => $poItemId,
                'item_id' => $baris->item_id,
                'quantity' => $qty,
            ];
        }

        try {
            $penerimaan = $this->receipts->receive($po, $items, $request->user());
        } catch (InventoryException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('inventory.po.show', $po)->with('sukses', "Penerimaan {$penerimaan->receipt_number} tercatat.");
    }

    public function verify(Request $request, GoodsReceipt $penerimaan): RedirectResponse
    {
        $data = $request->validate([
            'verification_outcome' => ['required', Rule::in(['sesuai', 'tidak-sesuai'])],
            'verification_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['verification_outcome' => 'hasil verifikasi']);

        try {
            $this->receipts->verify($penerimaan, $data['verification_outcome'], $data['verification_note'] ?? null, $request->user());
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Penerimaan {$penerimaan->receipt_number} diverifikasi.");
    }
}
