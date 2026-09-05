<?php

namespace App\Modules\Blood\Http\Controllers;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Models\Donor;
use App\Modules\Blood\Services\BloodException;
use App\Modules\Blood\Services\BloodUnitService;
use App\Modules\Blood\Services\TransfusionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

        try {
            $unit = $this->units->collect($data);
        } catch (BloodException $e) {
            // Mis. pendonornya sedang dicekal (utd_cekal_darah).
            return back()->withInput()->with('galat', $e->getMessage());
        }

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

    /** utd_pemisahan_darah — pisahkan unit whole-blood jadi beberapa unit komponen. */
    public function separate(Request $request, BloodUnit $unit): RedirectResponse
    {
        // Form UI mengirim baris tetap untuk prc/plasma/platelet; baris yang
        // volume-nya tidak diisi berarti komponen itu tidak dipisah, buang
        // dulu sebelum divalidasi supaya tidak wajib mengisi ketiganya.
        $terisi = collect($request->input('komponen', []))
            ->filter(fn (array $baris) => filled($baris['volume_ml'] ?? null))
            ->values()
            ->all();

        $validator = Validator::make(['komponen' => $terisi], [
            'komponen' => ['required', 'array', 'min:1'],
            'komponen.*.component' => ['required', 'in:prc,plasma,platelet'],
            'komponen.*.volume_ml' => ['required', 'integer', 'min:1'],
            'komponen.*.expiry_date' => ['required', 'date', 'after:today'],
        ], [], ['komponen' => 'daftar komponen']);
        $validator->validate();
        $data = $validator->validated();

        try {
            $anak = $this->units->separate($unit, $data['komponen'], $request->user());
        } catch (BloodException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Unit darah {$unit->unit_number} dipisah jadi " . $anak->count() . ' unit komponen: ' . $anak->pluck('unit_number')->implode(', ') . '.');
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
