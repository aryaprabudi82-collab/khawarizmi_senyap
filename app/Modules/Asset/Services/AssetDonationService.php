<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\DonationReceipt;
use App\Modules\Asset\Models\Donor;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * hibah_aset_inventaris (+ asal_hibah, kode reused lintas-domain,
 * sudah context=pharmacy — lihat catatan modul). Sama seperti
 * penerimaan, langsung membuat baris asset.assets baru per unit
 * lewat AssetService::createAsset(), tanpa StockLedger — nilai
 * perolehan dicatat 0 supaya tidak ikut menghitung sebagai belanja
 * sungguhan kalau laporan nilai pengadaan aset ditambahkan nanti.
 */
class AssetDonationService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly AssetService $assets,
    ) {}

    public function createDonor(array $data): Donor
    {
        return Donor::query()->create($data);
    }

    /** @param array<int, array{item_name: string, category_id: int, quantity: int}> $items */
    public function receive(int $donorId, array $items, ?string $notes, User $actor): DonationReceipt
    {
        if ($items === []) {
            throw new AssetException('Hibah harus berisi minimal satu barang.');
        }

        return DB::transaction(function () use ($donorId, $items, $notes, $actor): DonationReceipt {
            $hibah = DonationReceipt::query()->create([
                'receipt_number' => $this->numbers->allocate('HBH'),
                'donor_id' => $donorId,
                'received_at' => now(),
                'received_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $baris) {
                $quantity = (int) $baris['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $hibah->items()->create([
                    'item_name' => $baris['item_name'],
                    'category_id' => $baris['category_id'],
                    'quantity' => $quantity,
                ]);

                for ($i = 0; $i < $quantity; $i++) {
                    $this->assets->createAsset([
                        'name' => $baris['item_name'],
                        'category_id' => $baris['category_id'],
                        'acquisition_date' => now()->toDateString(),
                        'acquisition_value' => 0,
                    ]);
                }
            }

            return $hibah->fresh('items');
        });
    }
}
