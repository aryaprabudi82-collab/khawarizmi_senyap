<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\DonationReceipt;
use App\Modules\Kitchen\Models\Donor;
use App\Modules\Kitchen\Models\Item;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** hibah_dapur. Mengikuti pola donors+donation_receipts persis, disederhanakan ke model non-batch. */
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
            throw new KitchenException('Hibah harus berisi minimal satu barang.');
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
