<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierReturn;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\SupplierReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ipsrs_returbeli. */
class SupplierReturnController
{
    public function __construct(private readonly SupplierReturnService $returns) {}

    public function index(): View
    {
        return view('inventory::retur.index', [
            'retur' => SupplierReturn::query()->with(['supplier', 'items'])->latest('returned_at')->limit(50)->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'barang' => Item::query()->where('is_active', true)->orderBy('name')->get(),
            'penerimaan' => GoodsReceipt::query()->with('purchaseOrder')->latest('received_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'goods_receipt_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
            'item_id' => ['required', 'array', 'min:1'],
            'item_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'suplier', 'reason' => 'alasan']);

        $items = [];
        foreach ($data['item_id'] as $i => $itemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[] = ['item_id' => $itemId, 'quantity' => $qty, 'note' => null];
            }
        }

        $penerimaan = ! empty($data['goods_receipt_id']) ? GoodsReceipt::query()->find($data['goods_receipt_id']) : null;

        try {
            $retur = $this->returns->request((int) $data['supplier_id'], $penerimaan, $items, $data['reason'], $request->user());
        } catch (InventoryException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Retur {$retur->return_number} tercatat.");
    }

    public function complete(Request $request, SupplierReturn $retur): RedirectResponse
    {
        try {
            $this->returns->complete($retur, $request->user());
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Retur {$retur->return_number} selesai, stok terkait dikurangi.");
    }
}
