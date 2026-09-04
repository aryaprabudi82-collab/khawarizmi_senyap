<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\RetailSale;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\RetailSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** penjualan_obat + piutang_obat + retur_dari_pembeli + retur_piutang_pasien. */
class RetailSaleController
{
    public function __construct(private readonly RetailSaleService $sales) {}

    public function index(): View
    {
        return view('pharmacy::penjualan.index', [
            'penjualan' => RetailSale::query()->with('items')->latest('created_at')->limit(50)->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_identity_number' => ['nullable', 'string', 'max:40'],
            'payment_status' => ['required', Rule::in(['lunas', 'piutang'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['required', 'array'],
            'unit_price.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['customer_name' => 'nama pembeli', 'payment_status' => 'cara bayar']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = ['quantity' => $qty, 'unit_price' => (float) ($data['unit_price'][$i] ?? 0)];
            }
        }

        $header = collect($data)->except(['drug_id', 'quantity', 'unit_price'])->all();

        try {
            $penjualan = $this->sales->sell($header, $items, $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Penjualan {$penjualan->sale_number} tercatat.");
    }

    public function storeReturn(Request $request, RetailSale $penjualan): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'sale_item_id' => ['required', 'array', 'min:1'],
            'sale_item_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['reason' => 'alasan']);

        $items = [];
        foreach ($data['sale_item_id'] as $i => $itemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$itemId] = $qty;
            }
        }

        try {
            $retur = $this->sales->returnItems($penjualan, $items, $data['reason'], $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Retur {$retur->return_number} tercatat.");
    }
}
