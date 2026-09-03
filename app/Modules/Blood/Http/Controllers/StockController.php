<?php

namespace App\Modules\Blood\Http\Controllers;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Models\Donor;
use App\Modules\Blood\Services\BloodException;
use App\Modules\Blood\Services\BloodUnitService;
use App\Modules\Blood\Services\TransfusionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController
{
    public function __construct(
        private readonly BloodUnitService $units,
        private readonly TransfusionService $transfusion,
    ) {}

    public function index(): View
    {
        return view('blood::stok.index', [
            'unit' => BloodUnit::query()->with('donor')->latest('collected_at')->limit(50)->get(),
            'pendonorAktif' => Donor::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function collect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'donor_id' => ['nullable', 'integer'],
            'blood_type' => ['required', 'in:A,B,AB,O'],
            'rhesus' => ['required', 'in:+,-'],
            'component' => ['required', 'in:whole-blood,prc,plasma,platelet'],
            'volume_ml' => ['required', 'integer', 'min:1'],
            'collected_at' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after:collected_at'],
        ], [], [
            'donor_id' => 'pendonor', 'blood_type' => 'golongan darah', 'rhesus' => 'rhesus', 'component' => 'komponen',
            'volume_ml' => 'volume', 'collected_at' => 'waktu pengambilan', 'expiry_date' => 'tanggal kedaluwarsa',
        ]);

        $unit = $this->units->collect($data);

        return back()->with('sukses', "Unit darah {$unit->unit_number} tercatat, status karantina.");
    }

    public function release(Request $request, BloodUnit $unit): RedirectResponse
    {
        try {
            $this->units->release($unit, $request->user());
        } catch (BloodException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Unit darah {$unit->unit_number} dirilis, status tersedia.");
    }

    public function hold(Request $request, BloodUnit $unit): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);

        try {
            $this->units->hold($unit, $request->user(), $data['reason']);
        } catch (BloodException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Unit darah {$unit->unit_number} ditahan.");
    }

    public function reject(Request $request, BloodUnit $unit): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);

        try {
            $this->units->reject($unit, $request->user(), $data['reason']);
        } catch (BloodException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Unit darah {$unit->unit_number} ditolak.");
    }

    public function issue(Request $request, BloodUnit $unit): RedirectResponse
    {
        $data = $request->validate([
            'patient_name' => ['required', 'string', 'max:150'],
            'indication' => ['nullable', 'string', 'max:1000'],
        ], [], ['patient_name' => 'nama pasien']);

        try {
            $terbit = $this->transfusion->issue($unit, $data['patient_name'], $request->user(), indication: $data['indication'] ?? null);
        } catch (BloodException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Unit darah {$unit->unit_number} dikeluarkan ({$terbit->issue_number}) untuk {$data['patient_name']}.");
    }
}
