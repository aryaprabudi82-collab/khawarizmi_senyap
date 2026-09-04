<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\PatientStockRequest;
use App\Modules\Pharmacy\Services\PatientStockRequestService;
use App\Modules\Pharmacy\Services\PharmacyException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** permintaan_stok_obat_pasien + stok_obat_pasien. */
class PatientStockRequestController
{
    public function __construct(private readonly PatientStockRequestService $requests) {}

    public function index(): View
    {
        return view('pharmacy::permintaan-pasien.index', [
            'permintaan' => PatientStockRequest::query()->with('items')->latest('created_at')->limit(50)->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** Dipakai form pencarian kunjungan sebelum mengajukan — staf tidak tahu registration_id mentah. */
    public function searchRegistration(Request $request): JsonResponse
    {
        $nomor = (string) $request->query('nomor', '');
        $kunjungan = $nomor !== '' ? $this->requests->findRegistrationByNumber($nomor) : null;

        return response()->json(['kunjungan' => $kunjungan]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['registration_id' => 'kunjungan']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$drugId] = $qty;
            }
        }

        try {
            $permintaan = $this->requests->request((int) $data['registration_id'], $items, $data['notes'] ?? null, $request->user()->id);
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} tercatat.");
    }

    public function reject(Request $request, PatientStockRequest $permintaan): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']], [], ['rejection_reason' => 'alasan']);

        try {
            $this->requests->reject($permintaan, $data['rejection_reason']);
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} ditolak.");
    }

    public function issue(Request $request, PatientStockRequest $permintaan): RedirectResponse
    {
        try {
            $this->requests->issue($permintaan, $request->user());
        } catch (PharmacyException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Stok untuk {$permintaan->request_number} sudah dikeluarkan.");
    }
}
