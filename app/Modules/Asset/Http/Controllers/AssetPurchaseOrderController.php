<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetManufacturer;
use App\Modules\Asset\Models\AssetType;
use App\Modules\Asset\Models\PurchaseOrder;
use App\Modules\Asset\Models\Requisition;
use App\Modules\Asset\Models\Supplier;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetPurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** pengadaan_aset_inventaris. storeSupplier() melayani suplier_inventaris — gerbang terpisah, ditumpuk di layar yang sama. */
class AssetPurchaseOrderController
{
    public function __construct(private readonly AssetPurchaseOrderService $orders) {}

    public function index(): View
    {
        return view('asset::po.index', [
            'po' => PurchaseOrder::query()->with('supplier')->latest('created_at')->limit(50)->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'kategori' => AssetCategory::query()->orderBy('name')->get(),
            'jenis' => AssetType::query()->orderBy('name')->get(),
            'produsen' => AssetManufacturer::query()->orderBy('name')->get(),
            'pengajuanTerbuka' => Requisition::query()->where('status', 'disetujui')->orderBy('requisition_number')->get(),
        ]);
    }

    public function show(PurchaseOrder $po): View
    {
        return view('asset::po.show', ['po' => $po->load(['items', 'supplier', 'receipts'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'requisition_id' => ['nullable', 'integer'],
            'item_name' => ['required', 'array', 'min:1'],
            'item_name.*' => ['nullable', 'string', 'max:200'],
            'category_id' => ['required', 'array'],
            'category_id.*' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'array'],
            'type_id.*' => ['nullable', 'integer'],
            'manufacturer_id' => ['nullable', 'array'],
            'manufacturer_id.*' => ['nullable', 'integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['required', 'array'],
            'unit_price.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'suplier']);

        $items = [];
        foreach ($data['item_name'] as $i => $namaBarang) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0 && trim((string) $namaBarang) !== '' && ! empty($data['category_id'][$i])) {
                $items[] = [
                    'item_name' => $namaBarang,
                    'category_id' => (int) $data['category_id'][$i],
                    'type_id' => $data['type_id'][$i] ?? null,
                    'manufacturer_id' => $data['manufacturer_id'][$i] ?? null,
                    'quantity' => $qty,
                    'unit_price' => (float) ($data['unit_price'][$i] ?? 0),
                ];
            }
        }

        $requisition = ! empty($data['requisition_id']) ? Requisition::query()->find($data['requisition_id']) : null;

        try {
            $po = $this->orders->create((int) $data['supplier_id'], $items, $request->user()->id, $requisition);
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('asset.po.show', $po)->with('sukses', "PO {$po->po_number} dibuat.");
    }

    public function submit(PurchaseOrder $po): RedirectResponse
    {
        try {
            $this->orders->submit($po);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "PO {$po->po_number} dikirim ke suplier.");
    }

    public function cancel(PurchaseOrder $po): RedirectResponse
    {
        try {
            $this->orders->cancel($po);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "PO {$po->po_number} dibatalkan.");
    }

    /** suplier_inventaris. */
    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Supplier::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        Supplier::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', "Suplier {$data['name']} ditambahkan.");
    }
}
