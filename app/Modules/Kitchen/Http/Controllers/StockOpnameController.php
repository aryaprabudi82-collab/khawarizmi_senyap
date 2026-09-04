<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Modules\Kitchen\Models\StockOpname;
use App\Modules\Kitchen\Services\KitchenException;
use App\Modules\Kitchen\Services\StockOpnameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** dapur_opname. */
class StockOpnameController
{
    public function __construct(private readonly StockOpnameService $opnames) {}

    public function index(): View
    {
        return view('kitchen::opname.index', [
            'opname' => StockOpname::query()->with('items')->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function show(StockOpname $opname): View
    {
        return view('kitchen::opname.show', ['opname' => $opname->load('items.item')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $opname = $this->opnames->start($data['notes'] ?? null, $request->user()->id);

        return redirect()->route('kitchen.opname.show', $opname)->with('sukses', "Opname {$opname->opname_number} dibuka, {$opname->items->count()} barang perlu dihitung.");
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
        } catch (KitchenException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil hitung fisik disimpan.');
    }

    public function complete(Request $request, StockOpname $opname): RedirectResponse
    {
        try {
            $this->opnames->complete($opname, $request->user());
        } catch (KitchenException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Opname {$opname->opname_number} selesai, selisih sudah disesuaikan.");
    }
}
