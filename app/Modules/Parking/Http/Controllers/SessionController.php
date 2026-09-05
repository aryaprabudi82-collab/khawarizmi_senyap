<?php

namespace App\Modules\Parking\Http\Controllers;

use App\Modules\Parking\Models\BarcodeCard;
use App\Modules\Parking\Models\Rate;
use App\Modules\Parking\Models\Session;
use App\Modules\Parking\Services\ParkingException;
use App\Modules\Parking\Services\ParkingSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar petugas gerbang (kode parkir_in). Sisi keluar ada di layar yang
 * sama karena petugasnya sama dan Khanza pun menyimpan masuk & keluar
 * pada satu baris `parkir` — tidak ada kode menu terpisah untuk keluar.
 */
class SessionController
{
    public function __construct(private readonly ParkingSessionService $sesi) {}

    public function index(Request $request): View
    {
        $cari = trim((string) $request->query('cari', ''));

        return view('parking::sesi.index', [
            'cari' => $cari,
            'ditemukan' => $cari === '' ? null : $this->sesi->cariSesiTerbuka($cari),
            'terbuka' => Session::query()->terbuka()->with('rate', 'barcodeCard')->orderBy('entered_at')->get(),
            'terakhir' => Session::query()->whereNotNull('exited_at')->with('rate')->latest('exited_at')->limit(20)->get(),
            'tarifAktif' => Rate::query()->where('is_active', true)->orderBy('name')->get(),
            'kartuTersedia' => BarcodeCard::query()
                ->where('is_active', true)
                ->whereNotIn('id', Session::query()->terbuka()->whereNotNull('barcode_card_id')->select('barcode_card_id'))
                ->orderBy('card_number')
                ->get(),
        ]);
    }

    public function checkIn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rate_id' => ['required', 'integer'],
            'vehicle_number' => ['required', 'string', 'max:15'],
            'barcode_card_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'rate_id' => 'jenis parkir', 'vehicle_number' => 'nomor kendaraan', 'barcode_card_id' => 'kartu',
        ]);

        $tarif = Rate::query()->findOrFail($data['rate_id']);
        $kartu = empty($data['barcode_card_id']) ? null : BarcodeCard::query()->findOrFail($data['barcode_card_id']);

        try {
            $sesi = $this->sesi->checkIn($tarif, $data['vehicle_number'], $request->user(), $kartu, $data['notes'] ?? null);
        } catch (ParkingException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kendaraan {$sesi->vehicle_number} masuk pukul {$sesi->entered_at->format('H:i')}.");
    }

    public function checkOut(Request $request, Session $sesi): RedirectResponse
    {
        try {
            $sesi = $this->sesi->checkOut($sesi, $request->user());
        } catch (ParkingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', sprintf(
            'Kendaraan %s keluar setelah %d menit, biaya Rp %s.',
            $sesi->vehicle_number,
            $sesi->duration_minutes,
            number_format($sesi->total_fee, 0, ',', '.')
        ));
    }
}
