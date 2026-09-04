<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\DonationReceipt;
use App\Modules\Asset\Models\Donor;
use App\Modules\Asset\Services\AssetDonationService;
use App\Modules\Asset\Services\AssetException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** hibah_aset_inventaris. */
class AssetDonationController
{
    public function __construct(private readonly AssetDonationService $donations) {}

    public function index(): View
    {
        return view('asset::hibah.index', [
            'hibah' => DonationReceipt::query()->with(['donor', 'items.category'])->latest('created_at')->limit(50)->get(),
            'donor' => Donor::query()->where('is_active', true)->orderBy('name')->get(),
            'kategori' => AssetCategory::query()->orderBy('name')->get(),
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
            'item_name' => ['required', 'array', 'min:1'],
            'item_name.*' => ['nullable', 'string', 'max:200'],
            'category_id' => ['required', 'array'],
            'category_id.*' => ['nullable', 'integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'integer', 'min:0'],
        ], [], ['donor_id' => 'pemberi hibah']);

        $items = [];
        foreach ($data['item_name'] as $i => $namaBarang) {
            $qty = (int) ($data['quantity'][$i] ?? 0);
            if ($qty > 0 && trim((string) $namaBarang) !== '' && ! empty($data['category_id'][$i])) {
                $items[] = ['item_name' => $namaBarang, 'category_id' => (int) $data['category_id'][$i], 'quantity' => $qty];
            }
        }

        try {
            $hibah = $this->donations->receive((int) $data['donor_id'], $items, $data['notes'] ?? null, $request->user());
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Hibah {$hibah->receipt_number} tercatat.");
    }
}
