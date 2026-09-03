<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController
{
    public function __construct(
        private readonly ItemService $items,
        private readonly StockLedger $ledger,
    ) {}

    public function index(): View
    {
        return view('inventory::master.index', [
            'barang' => Item::query()->with('category')->orderBy('name')->get(),
            'kategori' => ItemCategory::query()->orderBy('name')->get(),
            'suplier' => Supplier::query()->orderBy('name')->get(),
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique(Item::class, 'code')],
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'category_id' => 'kategori', 'unit_of_measure' => 'satuan', 'reorder_point' => 'ambang stok']);

        $this->items->createItem($data + ['is_active' => true, 'quantity_on_hand' => 0]);

        return back()->with('sukses', "Barang {$data['name']} ditambahkan.");
    }

    public function updateItem(Request $request, Item $barang): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'category_id' => 'kategori', 'unit_of_measure' => 'satuan']);

        $data['is_active'] = $request->boolean('is_active');

        $this->items->updateItem($barang, $data);

        return back()->with('sukses', "Barang {$barang->name} diperbarui.");
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(ItemCategory::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->items->createCategory($data + ['is_active' => true]);

        return back()->with('sukses', "Kategori {$data['name']} ditambahkan.");
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Supplier::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->items->createSupplier($data + ['is_active' => true]);

        return back()->with('sukses', "Suplier {$data['name']} ditambahkan.");
    }

    public function receive(Request $request, Item $barang): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'source' => ['required', 'in:pembelian,hibah,retur-suplier'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['quantity' => 'jumlah', 'source' => 'sumber']);

        $this->ledger->receive($barang->id, (float) $data['quantity'], $data['source'], actor: $request->user(), note: $data['note'] ?? null);

        return back()->with('sukses', "Stok {$barang->name} bertambah {$data['quantity']} {$barang->unit_of_measure}.");
    }

    public function opname(Request $request, Item $barang): RedirectResponse
    {
        $data = $request->validate([
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['counted_quantity' => 'hasil hitung fisik']);

        try {
            $this->ledger->opname($barang->id, (float) $data['counted_quantity'], $request->user(), $data['note'] ?? null);
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Stok {$barang->name} disesuaikan hasil opname.");
    }
}
