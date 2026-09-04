<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Modules\Kitchen\Models\GoodsReceipt;
use App\Modules\Kitchen\Models\PurchaseOrder;
use App\Modules\Kitchen\Services\GoodsReceiptService;
use App\Modules\Kitchen\Services\KitchenException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** dapur_pemesanan dan verifikasi_penerimaan_dapur. */
class GoodsReceiptController
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(): View
    {
        return view('kitchen::penerimaan.index', [
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
        } catch (KitchenException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('kitchen.po.show', $po)->with('sukses', "Penerimaan {$penerimaan->receipt_number} tercatat.");
    }

    public function verify(Request $request, GoodsReceipt $penerimaan): RedirectResponse
    {
        $data = $request->validate([
            'verification_outcome' => ['required', Rule::in(['sesuai', 'tidak-sesuai'])],
            'verification_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['verification_outcome' => 'hasil verifikasi']);

        try {
            $this->receipts->verify($penerimaan, $data['verification_outcome'], $data['verification_note'] ?? null, $request->user());
        } catch (KitchenException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Penerimaan {$penerimaan->receipt_number} diverifikasi.");
    }
}
