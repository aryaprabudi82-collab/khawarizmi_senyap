<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\InpatientCostEstimate;
use App\Modules\Finance\Services\CostEstimateService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\RegistrationContext;
use App\Modules\Finance\Services\RoomRateContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CostEstimateController
{
    public function __construct(
        private readonly CostEstimateService $estimates,
        private readonly RegistrationContext $registrations,
        private readonly RoomRateContext $roomRates,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $daftar = InpatientCostEstimate::query()
            ->orderByDesc('prepared_at')
            ->paginate(50)
            ->withQueryString();

        return view('finance::estimates.index', [
            'daftar' => $daftar,
            'q' => $q,
            // Hanya kunjungan rawat inap yang relevan untuk perkiraan biaya ranap.
            'hasilPencarian' => $q !== '' ? $this->registrations->search($q, 'ranap') : collect(),
            'kelasKamar' => InpatientCostEstimate::CLASSES,
            'tarifKelas' => $this->roomRates->all()->keyBy('room_class'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'room_class' => ['required', 'string', 'in:' . implode(',', InpatientCostEstimate::CLASSES)],
            'estimated_days' => ['required', 'integer', 'min:1'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'registration_id' => 'kunjungan', 'room_class' => 'kelas kamar',
            'estimated_days' => 'perkiraan lama rawat', 'other_charges' => 'perkiraan biaya lain-lain',
        ]);

        try {
            $estimasi = $this->estimates->create(
                registrationId: $data['registration_id'],
                roomClass: $data['room_class'],
                estimatedDays: (int) $data['estimated_days'],
                otherCharges: (float) ($data['other_charges'] ?? 0),
                note: $data['note'] ?? null,
                actor: $request->user(),
            );
        } catch (FinanceException $e) {
            return back()->with('galat', $e->getMessage())->withInput();
        }

        return redirect()->route('estimasi-ranap.cetak', $estimasi)
            ->with('sukses', "Perkiraan biaya {$estimasi->estimate_number} tersimpan.");
    }

    public function print(InpatientCostEstimate $estimasi): View
    {
        return view('finance::estimates.cetak', ['estimasi' => $estimasi]);
    }
}
