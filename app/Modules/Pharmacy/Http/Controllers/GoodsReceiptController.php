<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\GoodsReceipt;
use App\Modules\Pharmacy\Models\PurchaseOrder;
use App\Modules\Pharmacy\Services\GoodsReceiptService;
use App\Modules\Pharmacy\Services\PharmacyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** bayar_pemesanan_obat (terima + bayar) dan verifikasi_penerimaan_farmasi (QC pasca-terima) — lihat catatan migrasi. */
class GoodsReceiptController
{
    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(): View
    {
        return view('pharmacy::penerimaan.index', [
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
            'batch_number' => ['required', 'array'],
            'batch_number.*' => ['nullable', 'string', 'max:40'],
            'expiry_date' => ['nullable', 'array'],
            'expiry_date.*' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $items = [];
        foreach ($data['purchase_order_item_id'] as $i => $poItemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            $batch = trim($data['batch_number'][$i] ?? '');

            if ($qty <= 0 || $batch === '') {
                continue;
            }

            $baris = $po->items()->whereKey($poItemId)->firstOrFail();

            $items[] = [
                'purchase_order_item_id' => $poItemId,
                'drug_id' => $baris->drug_id,
                'quantity' => $qty,
                'batch_number' => $batch,
                'expiry_date' => $data['expiry_date'][$i] ?? null,
                'cost_price' => (float) $baris->unit_price,
            ];
        }

        try {
            $penerimaan = $this->receipts->receive($po, $items, [
                'invoice_number' => $data['invoice_number'] ?? null,
                'paid_amount' => $data['paid_amount'] ?? 0,
            ], $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('pharmacy.po.show', $po)->with('sukses', "Penerimaan {$penerimaan->receipt_number} tercatat.");
    }

    public function verify(Request $request, GoodsReceipt $penerimaan): RedirectResponse
    {
        $data = $request->validate([
            'verification_outcome' => ['required', Rule::in(['sesuai', 'tidak-sesuai'])],
            'verification_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['verification_outcome' => 'hasil verifikasi']);

        try {
            $this->receipts->verify($penerimaan, $data['verification_outcome'], $data['verification_note'] ?? null, $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Penerimaan {$penerimaan->receipt_number} diverifikasi.");
    }
}
