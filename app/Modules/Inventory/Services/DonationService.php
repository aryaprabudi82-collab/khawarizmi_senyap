<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\DonationReceipt;
use App\Modules\Inventory\Models\Donor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** hibah_non_medis (+ asal_hibah). Mengikuti pola donors+donation_receipts farmasi, disederhanakan ke model non-batch. */
class DonationService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    public function createDonor(array $data): Donor
    {
        return Donor::query()->create($data);
    }

    /** @param array<int, float> $items itemId => quantity */
    public function receive(int $donorId, array $items, ?string $notes, User $actor): DonationReceipt
    {
        if ($items === []) {
            throw new InventoryException('Hibah harus berisi minimal satu barang.');
        }

        return DB::transaction(function () use ($donorId, $items, $notes, $actor): DonationReceipt {
            $hibah = DonationReceipt::query()->create([
                'receipt_number' => $this->numbers->allocate('HBH'),
                'donor_id' => $donorId,
                'received_at' => now(),
                'received_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $itemId => $quantity) {
                $quantity = (float) $quantity;

                if ($quantity <= 0) {
                    continue;
                }

                $hibah->items()->create([
                    'item_id' => $itemId,
                    'item_name' => Item::query()->whereKey($itemId)->value('name') ?? '—',
                    'quantity' => $quantity,
                ]);

                // source 'hibah', bukan 'pembelian' — supaya nilai_penerimaan_vendor_nonmedis_perbulan
                // (dihitung dari goods_receipt_items/purchase_order_items, bukan stock_movements)
                // otomatis tidak ikut menghitung barang gratis ini, sebab donasi memang
                // tidak pernah lewat goods_receipts sama sekali.
                $this->ledger->receive(
                    itemId: $itemId,
                    quantity: $quantity,
                    source: 'hibah',
                    referenceType: 'donation-receipt',
                    referenceId: $hibah->id,
                    actor: $actor,
                    note: "Hibah {$hibah->receipt_number}",
                );
            }

            return $hibah->fresh('items');
        });
    }
}
