<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\PurchaseOrder;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetGoodsReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** penerimaan_aset_inventaris. */
class AssetGoodsReceiptController
{
    public function __construct(private readonly AssetGoodsReceiptService $receipts) {}

    public function store(Request $request, PurchaseOrder $po): RedirectResponse
    {
        $data = $request->validate([
            'purchase_order_item_id' => ['required', 'array', 'min:1'],
            'purchase_order_item_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $items = [];
        foreach ($data['purchase_order_item_id'] as $i => $poItemId) {
            $qty = (int) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[] = ['purchase_order_item_id' => $poItemId, 'quantity' => $qty];
            }
        }

        try {
            $penerimaan = $this->receipts->receive($po, $items, $request->user());
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('asset.po.show', $po)->with('sukses', "Penerimaan {$penerimaan->receipt_number} tercatat, {$penerimaan->items->sum('quantity_received')} aset baru dibuat.");
    }
}
