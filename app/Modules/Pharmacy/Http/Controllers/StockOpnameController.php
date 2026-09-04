<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Models\StockOpname;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\StockOpnameService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** stok_opname_obat (DlgInputStok). */
class StockOpnameController
{
    public function __construct(private readonly StockOpnameService $opnames) {}

    public function index(): View
    {
        return view('pharmacy::opname.index', [
            'opname' => StockOpname::query()->with(['location', 'items'])->latest('created_at')->limit(20)->get(),
            'lokasi' => StockLocation::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(StockOpname $opname): View
    {
        return view('pharmacy::opname.show', ['opname' => $opname->load(['location', 'items.batch.drug'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['location_id' => 'lokasi']);

        $opname = $this->opnames->start((int) $data['location_id'], $data['notes'] ?? null, $request->user()->id);

        return redirect()->route('pharmacy.opname.show', $opname)->with('sukses', "Opname {$opname->opname_number} dibuka, {$opname->items->count()} batch perlu dihitung.");
    }

    public function recordCount(Request $request, StockOpname $opname): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'array'],
            'item_id.*' => ['integer'],
            'counted_quantity' => ['required', 'array'],
            'counted_quantity.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            foreach ($data['item_id'] as $i => $itemId) {
                if (($data['counted_quantity'][$i] ?? null) === null || $data['counted_quantity'][$i] === '') {
                    continue;
                }

                $this->opnames->recordCount($opname, (int) $itemId, (float) $data['counted_quantity'][$i]);
            }
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil hitung fisik disimpan.');
    }

    public function complete(Request $request, StockOpname $opname): RedirectResponse
    {
        try {
            $this->opnames->complete($opname, $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Opname {$opname->opname_number} selesai, selisih sudah disesuaikan.");
    }
}
