<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Modules\Kitchen\Models\DonationReceipt;
use App\Modules\Kitchen\Models\Donor;
use App\Modules\Kitchen\Models\Item;
use App\Modules\Kitchen\Services\DonationService;
use App\Modules\Kitchen\Services\KitchenException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** hibah_dapur. */
class DonationController
{
    public function __construct(private readonly DonationService $donations) {}

    public function index(): View
    {
        return view('kitchen::hibah.index', [
            'hibah' => DonationReceipt::query()->with(['donor', 'items'])->latest('created_at')->limit(50)->get(),
            'donor' => Donor::query()->where('is_active', true)->orderBy('name')->get(),
            'barang' => Item::query()->where('is_active', true)->orderBy('name')->get(),
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
            'item_id' => ['required', 'array', 'min:1'],
            'item_id.*' => ['integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['donor_id' => 'pemberi hibah']);

        $items = [];
        foreach ($data['item_id'] as $i => $itemId) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0) {
                $items[$itemId] = $qty;
            }
        }

        try {
            $hibah = $this->donations->receive((int) $data['donor_id'], $items, $data['notes'] ?? null, $request->user());
        } catch (KitchenException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Hibah {$hibah->receipt_number} tercatat.");
    }
}
