<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Models\DonationReceipt;
use App\Modules\Pharmacy\Models\Donor;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Services\DonationReceiptService;
use App\Modules\Pharmacy\Services\PharmacyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** hibah_obat_bhp + asal_hibah. */
class DonationReceiptController
{
    public function __construct(private readonly DonationReceiptService $donations) {}

    public function index(): View
    {
        return view('pharmacy::hibah.index', [
            'hibah' => DonationReceipt::query()->with(['donor', 'items'])->latest('created_at')->limit(50)->get(),
            'donor' => Donor::query()->where('is_active', true)->orderBy('name')->get(),
            'obat' => Drug::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeDonor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Donor::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:100'],
        ], [], ['code' => 'kode', 'name' => 'nama']);

        $this->donations->createDonor($data + ['is_active' => true]);

        return back()->with('sukses', "Pemberi hibah {$data['name']} ditambahkan.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'donor_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'drug_id' => ['required', 'array', 'min:1'],
            'drug_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
            'batch_number' => ['required', 'array'],
            'batch_number.*' => ['nullable', 'string', 'max:40'],
            'expiry_date' => ['nullable', 'array'],
            'expiry_date.*' => ['nullable', 'date'],
        ], [], ['donor_id' => 'pemberi hibah']);

        $items = [];
        foreach ($data['drug_id'] as $i => $drugId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            $batch = trim($data['batch_number'][$i] ?? '');

            if ($qty > 0 && $batch !== '') {
                $items[$drugId] = ['quantity' => $qty, 'batch_number' => $batch, 'expiry_date' => $data['expiry_date'][$i] ?? null];
            }
        }

        try {
            $hibah = $this->donations->receive((int) $data['donor_id'], $items, $data['notes'] ?? null, $request->user());
        } catch (PharmacyException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Hibah {$hibah->receipt_number} tercatat.");
    }
}
