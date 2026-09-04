<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\GoodsReceipt;
use App\Modules\Pharmacy\Models\Supplier;
use App\Modules\Pharmacy\Models\SupplierReturn;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\SupplierReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** retur_ke_suplier (DlgReturBeli). */
class SupplierReturnController
{
    public function __construct(private readonly SupplierReturnService $returns) {}

    public function index(): View
    {
        return view('pharmacy::retur.index', [
            'retur' => SupplierReturn::query()->with(['supplier', 'items'])->latest('returned_at')->limit(50)->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
            'penerimaan' => GoodsReceipt::query()->with('purchaseOrder')->latest('received_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'goods_receipt_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'batch_number' => ['nullable', 'array'],
            'batch_number.*' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'suplier', 'reason' => 'alasan']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[] = [
                    'drug_id' => $drugId,
                    'batch_number' => $data['batch_number'][$i] ?? null,
                    'quantity' => $qty,
                    'note' => null,
                ];
            }
        }

        $penerimaan = ! empty($data['goods_receipt_id']) ? GoodsReceipt::query()->find($data['goods_receipt_id']) : null;

        try {
            $retur = $this->returns->request((int) $data['supplier_id'], $penerimaan, $items, $data['reason'], $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Retur {$retur->return_number} tercatat.");
    }

    public function complete(Request $request, SupplierReturn $retur): RedirectResponse
    {
        try {
            $this->returns->complete($retur, $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Retur {$retur->return_number} selesai, stok terkait dikurangi.");
    }
}
