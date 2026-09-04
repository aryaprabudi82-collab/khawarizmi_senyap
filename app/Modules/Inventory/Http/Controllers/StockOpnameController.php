<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\StockOpnameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** stok_opname_logistik (Stok Opname Non Medis). */
class StockOpnameController
{
    public function __construct(private readonly StockOpnameService $opnames) {}

    public function index(): View
    {
        return view('inventory::opname.index', [
            'opname' => StockOpname::query()->with('items')->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function show(StockOpname $opname): View
    {
        return view('inventory::opname.show', ['opname' => $opname->load('items.item')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $opname = $this->opnames->start($data['notes'] ?? null, $request->user()->id);

        return redirect()->route('inventory.opname.show', $opname)->with('sukses', "Opname {$opname->opname_number} dibuka, {$opname->items->count()} barang perlu dihitung.");
    }

    public function recordCount(Request $request, StockOpname $opname): RedirectResponse
    {
        $data = $request->validate([
            'baris_id' => ['required', 'array'],
            'baris_id.*' => ['integer'],
            'counted_quantity' => ['required', 'array'],
            'counted_quantity.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            foreach ($data['baris_id'] as $i => $barisId) {
                if (($data['counted_quantity'][$i] ?? null) === null || $data['counted_quantity'][$i] === '') {
                    continue;
                }

                $this->opnames->recordCount($opname, (int) $barisId, (float) $data['counted_quantity'][$i]);
            }
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil hitung fisik disimpan.');
    }

    public function complete(Request $request, StockOpname $opname): RedirectResponse
    {
        try {
            $this->opnames->complete($opname, $request->user());
        } catch (InventoryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Opname {$opname->opname_number} selesai, selisih sudah disesuaikan.");
    }
}
