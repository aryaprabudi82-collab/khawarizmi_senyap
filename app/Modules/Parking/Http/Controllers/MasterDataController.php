<?php

namespace App\Modules\Parking\Http\Controllers;

use App\Modules\Parking\Models\BarcodeCard;
use App\Modules\Parking\Models\Rate;
use App\Modules\Parking\Services\ParkingException;
use App\Modules\Parking\Services\ParkingMasterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Satu layar untuk dua kode Khanza: parkir_jenis (jenis & tarif) dan
 * parkir_barcode (stok kartu). Gerbangnya parkir_jenis; parkir_barcode
 * dilebur ke sini karena aktor dan layarnya sama — pola umbrella-gate
 * yang sama seperti inventaris_jenis/inventaris_produsen di domain G.
 */
class MasterDataController
{
    public function __construct(private readonly ParkingMasterService $master) {}

    public function index(): View
    {
        return view('parking::master.index', [
            'tarif' => Rate::query()->orderBy('name')->get(),
            'kartu' => BarcodeCard::query()->orderBy('card_number')->get(),
        ]);
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8', Rule::unique(Rate::class, 'code')],
            'name' => ['required', 'string', 'max:60'],
            'fee' => ['required', 'integer', 'min:0'],
            'basis' => ['required', 'in:jam,harian'],
            'free_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ], [], [
            'code' => 'kode', 'name' => 'nama jenis', 'fee' => 'tarif', 'basis' => 'basis tarif',
            'free_minutes' => 'menit bebas biaya',
        ]);

        $data['free_minutes'] = $data['free_minutes'] ?? 0;

        $tarif = $this->master->createRate($data);

        return back()->with('sukses', "Jenis parkir {$tarif->name} tersimpan.");
    }

    public function updateRate(Request $request, Rate $tarif): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'fee' => ['required', 'integer', 'min:0'],
            'basis' => ['required', 'in:jam,harian'],
            'free_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama jenis', 'fee' => 'tarif', 'basis' => 'basis tarif']);

        $data['free_minutes'] = $data['free_minutes'] ?? 0;
        $data['is_active'] = $request->boolean('is_active');

        $this->master->updateRate($tarif, $data);

        return back()->with('sukses', "Jenis parkir {$tarif->name} diperbarui.");
    }

    public function storeCard(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:32', Rule::unique(BarcodeCard::class, 'barcode')],
            'card_number' => ['required', 'string', 'max:8', Rule::unique(BarcodeCard::class, 'card_number')],
        ], [], ['barcode' => 'kode barcode', 'card_number' => 'nomor kartu']);

        $kartu = $this->master->registerCard($data['barcode'], $data['card_number']);

        return back()->with('sukses', "Kartu {$kartu->card_number} terdaftar.");
    }

    public function deactivateCard(BarcodeCard $kartu): RedirectResponse
    {
        try {
            $this->master->deactivateCard($kartu);
        } catch (ParkingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kartu {$kartu->card_number} dinonaktifkan.");
    }
}
