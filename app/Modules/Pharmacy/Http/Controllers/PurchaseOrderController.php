<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugRequisition;
use App\Modules\Pharmacy\Models\PurchaseOrder;
use App\Modules\Pharmacy\Models\Supplier;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** pengadaan_obat (DlgPembelian). Method print() melayani pemesanan_obat (Surat Pemesanan) — gerbang terpisah, data sama, lihat catatan migrasi. */
class PurchaseOrderController
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(): View
    {
        return view('pharmacy::po.index', [
            'po' => PurchaseOrder::query()->with('supplier')->latest('created_at')->limit(50)->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
            'pengajuanTerbuka' => DrugRequisition::query()->where('status', DrugRequisition::STATUS_DISETUJUI)->orderBy('requisition_number')->get(),
        ]);
    }

    public function show(PurchaseOrder $po): View
    {
        return view('pharmacy::po.show', [
            'po' => $po->load(['items', 'supplier', 'receipts']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'requisition_id' => ['nullable', 'integer'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'unit' => ['required', 'array'],
            'unit.*' => ['nullable', 'string', 'max:20'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['required', 'array'],
            'unit_price.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'suplier']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = [
                    'quantity' => $qty,
                    'unit' => $data['unit'][$i] ?? '',
                    'unit_price' => (float) ($data['unit_price'][$i] ?? 0),
                ];
            }
        }

        $requisition = ! empty($data['requisition_id']) ? DrugRequisition::query()->find($data['requisition_id']) : null;

        try {
            $po = $this->orders->create((int) $data['supplier_id'], $items, $request->user()->id, $requisition);
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('pharmacy.po.show', $po)->with('sukses', "PO {$po->po_number} dibuat.");
    }

    public function submit(Request $request, PurchaseOrder $po): RedirectResponse
    {
        try {
            $this->orders->submit($po);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "PO {$po->po_number} dikirim ke suplier.");
    }

    public function cancel(Request $request, PurchaseOrder $po): RedirectResponse
    {
        try {
            $this->orders->cancel($po);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "PO {$po->po_number} dibatalkan.");
    }

    public function print(PurchaseOrder $po): View
    {
        return view('pharmacy::po.cetak', ['po' => $po->load(['items', 'supplier'])]);
    }
}
