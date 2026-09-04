<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\DonationReceipt;
use App\Modules\Pharmacy\Models\Donor;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** hibah_obat_bhp + asal_hibah. */
class DonationReceiptService
{
    private const GUDANG_CODE = 'GUDANG';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    public function createDonor(array $data): Donor
    {
        return Donor::query()->create($data);
    }

    /** @param array<int, array{quantity: float, batch_number: string, expiry_date: ?string}> $items drugId => rincian */
    public function receive(int $donorId, array $items, ?string $notes, User $actor): DonationReceipt
    {
        if ($items === []) {
            throw new PharmacyException('Hibah harus berisi minimal satu obat/BHP.');
        }

        $gudang = StockLocation::query()->where('code', self::GUDANG_CODE)->firstOrFail();

        return DB::transaction(function () use ($donorId, $items, $notes, $actor, $gudang): DonationReceipt {
            $hibah = DonationReceipt::query()->create([
                'receipt_number' => $this->numbers->allocate('HBH'),
                'donor_id' => $donorId,
                'received_at' => now(),
                'received_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $drugId => $rincian) {
                $quantity = (float) $rincian['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $hibah->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity' => $quantity,
                    'batch_number' => $rincian['batch_number'],
                    'expiry_date' => $rincian['expiry_date'] ?? null,
                ]);

                // kind 'hibah' bukan 'masuk' — supaya laporan nilai pengadaan (yang menghitung dari cost_price x quantity 'masuk') tidak ikut menghitung barang gratis. cost_price dibiarkan 0.
                $this->ledger->receive(
                    drugId: $drugId,
                    locationId: $gudang->id,
                    batchNumber: $rincian['batch_number'],
                    quantity: $quantity,
                    expiryDate: $rincian['expiry_date'] ?? null,
                    costPrice: 0,
                    actor: $actor,
                    note: "Hibah {$hibah->receipt_number}",
                    kind: 'hibah',
                    referenceType: 'donation-receipt',
                    referenceId: $hibah->id,
                );
            }

            return $hibah->fresh('items');
        });
    }
}
