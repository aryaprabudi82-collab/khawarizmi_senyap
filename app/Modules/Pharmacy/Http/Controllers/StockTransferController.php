<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Models\StockTransfer;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** mutasi_barang (DlgMutasiBarang). */
class StockTransferController
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): View
    {
        $lokasi = StockLocation::query()->where('is_active', true)->orderBy('name')->get();
        $lokasiAsal = $request->query('from_location_id') ? (int) $request->query('from_location_id') : $lokasi->first()?->id;

        return view('pharmacy::mutasi.index', [
            'mutasi' => StockTransfer::query()->with(['fromLocation', 'toLocation', 'items.drug'])->latest('created_at')->limit(20)->get(),
            'lokasi' => $lokasi,
            'lokasiAsal' => $lokasiAsal,
            'batchAsal' => $lokasiAsal
                ? StockBatch::query()->with('drug')->where('location_id', $lokasiAsal)->where('quantity_on_hand', '>', 0)->orderBy('expiry_date')->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'batch_id' => ['required', 'array', 'min:1'],
            'batch_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['from_location_id' => 'lokasi asal', 'to_location_id' => 'lokasi tujuan']);

        $items = [];
        foreach ($data['batch_id'] as $i => $batchId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[] = ['batch_id' => $batchId, 'quantity' => $qty];
            }
        }

        try {
            $mutasi = $this->transfers->transfer(
                (int) $data['from_location_id'], (int) $data['to_location_id'], $items, $data['notes'] ?? null, $request->user(),
            );
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Mutasi {$mutasi->transfer_number} tercatat.");
    }
}
