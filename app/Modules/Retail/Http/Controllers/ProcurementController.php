<?php

namespace App\Modules\Retail\Http\Controllers;

use App\Modules\Retail\Models\GoodsReceipt;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\PurchaseOrder;
use App\Modules\Retail\Models\Requisition;
use App\Modules\Retail\Models\Supplier;
use App\Modules\Retail\Models\SupplierReturn;
use App\Modules\Retail\Services\ProcurementService;
use App\Modules\Retail\Services\RetailException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcurementController
{
    public function __construct(private readonly ProcurementService $pengadaan) {}

    public function index(): View
    {
        return view('retail::pengadaan.index', [
            'pengajuan' => Requisition::query()->with('items.product')->latest('requested_on')->limit(30)->get(),
            'pesanan' => PurchaseOrder::query()->with(['supplier', 'items.product', 'receipts.items'])
                ->latest('ordered_on')->limit(30)->get(),
            'penerimaan' => GoodsReceipt::query()->with(['order.supplier', 'items.product', 'payments'])
                ->latest('received_on')->limit(30)->get(),
            'retur' => SupplierReturn::query()->with(['supplier', 'items.product'])->latest('returned_on')->limit(20)->get(),
            'hutang' => $this->pengadaan->outstandingPayables(),
            'produk' => Product::query()->where('is_active', true)->orderBy('name')->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** Surat pemesanan — TAMPILAN CETAK pesanan yang sama, bukan entitas kedua. */
    public function printOrder(PurchaseOrder $pesanan): View
    {
        return view('retail::pengadaan.surat', [
            'pesanan' => $pesanan->load(['supplier', 'items.product']),
        ]);
    }

    public function storeRequisition(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'requested_by_name' => ['required', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:App\Modules\Retail\Models\Product,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [], ['requested_by_name' => 'pemohon', 'items' => 'barang']);

        $items = $this->bersihkanBaris($data['items']);
        unset($data['items']);

        try {
            $pengajuan = $this->pengadaan->requisition($data, $items, $request->user()->id);
        } catch (RetailException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan '.$pengajuan->requisition_number.' tercatat.');
    }

    public function decideRequisition(Request $request, Requisition $pengajuan): RedirectResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:setuju,tolak'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['keputusan' => 'keputusan', 'decision_note' => 'alasan']);

        try {
            $this->pengadaan->decideRequisition(
                $pengajuan,
                $data['keputusan'] === 'setuju',
                $data['decision_note'] ?? null,
                $request->user()->name
            );
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan '.$pengajuan->requisition_number.' diputuskan.');
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:App\Modules\Retail\Models\Supplier,id'],
            'requisition_id' => ['nullable', 'integer', 'exists:App\Modules\Retail\Models\Requisition,id'],
            'expected_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:App\Modules\Retail\Models\Product,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'suplier', 'items' => 'barang']);

        $suplier = Supplier::query()->findOrFail($data['supplier_id']);
        $dari = isset($data['requisition_id']) ? Requisition::query()->find($data['requisition_id']) : null;
        $items = $this->bersihkanBaris($data['items']);
        unset($data['items'], $data['supplier_id'], $data['requisition_id']);

        try {
            $pesanan = $this->pengadaan->order($suplier, $data, $items, $dari, $request->user()->id);
        } catch (RetailException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pesanan '.$pesanan->order_number.' dibuat sebagai draf.');
    }

    public function sendOrder(PurchaseOrder $pesanan): RedirectResponse
    {
        try {
            $this->pengadaan->sendOrder($pesanan);
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pesanan '.$pesanan->order_number.' ditandai terkirim.');
    }

    public function receive(Request $request, PurchaseOrder $pesanan): RedirectResponse
    {
        $data = $request->validate([
            'supplier_invoice_number' => ['nullable', 'string', 'max:60'],
            'due_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:App\Modules\Retail\Models\Product,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [], ['items' => 'barang diterima', 'due_on' => 'jatuh tempo']);

        $items = $this->bersihkanBaris($data['items']);
        unset($data['items']);

        try {
            $terima = $this->pengadaan->receive($pesanan, $data, $items, $request->user()->id, $request->user()->name);
        } catch (RetailException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Penerimaan '.$terima->receipt_number.' tercatat dan stoknya bertambah.');
    }

    public function pay(Request $request, GoodsReceipt $penerimaan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'jumlah bayar']);

        $jumlah = (float) $data['amount'];
        unset($data['amount']);

        try {
            $this->pengadaan->payReceipt($penerimaan, $jumlah, $data, $request->user()->id, $request->user()->name);
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pembayaran ke suplier tercatat.');
    }

    public function storeReturn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:App\Modules\Retail\Models\Supplier,id'],
            'receipt_id' => ['nullable', 'integer', 'exists:App\Modules\Retail\Models\GoodsReceipt,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:App\Modules\Retail\Models\Product,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [], ['supplier_id' => 'suplier', 'reason' => 'alasan retur', 'items' => 'barang']);

        $suplier = Supplier::query()->findOrFail($data['supplier_id']);
        $dari = isset($data['receipt_id']) ? GoodsReceipt::query()->find($data['receipt_id']) : null;
        $items = $this->bersihkanBaris($data['items']);
        unset($data['items'], $data['supplier_id'], $data['receipt_id']);

        try {
            $retur = $this->pengadaan->returnToSupplier($suplier, $data, $items, $dari, $request->user()->id);
        } catch (RetailException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Retur '.$retur->return_number.' tercatat dan stoknya berkurang.');
    }

    /**
     * Formulir menyediakan beberapa baris kosong; yang tidak diisi dibuang
     * dulu supaya baris kosong tidak jatuh sebagai galat validasi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bersihkanBaris(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn ($baris) => filled($baris['product_id'] ?? null) && (int) ($baris['quantity'] ?? 0) > 0
        ));
    }
}
