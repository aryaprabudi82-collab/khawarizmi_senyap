<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\ExternalPrescription;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * resep_luar — resep ditulis di luar RS, dilayani sebagai walk-in.
 * Tidak terikat registration_id (pasien belum tentu tercatat
 * identity.patients). Dispensing memotong stok DEPO-RJ seperti resep
 * biasa.
 */
class ExternalPrescriptionService
{
    private const DEPO_CODE = 'DEPO-RJ';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, array{quantity: float, unit_price: float}> $items drugId => rincian */
    public function receive(array $data, array $items, int $receivedBy): ExternalPrescription
    {
        if ($items === []) {
            throw new PharmacyException('Resep luar harus berisi minimal satu obat/BHP.');
        }

        return DB::transaction(function () use ($data, $items, $receivedBy): ExternalPrescription {
            $resep = ExternalPrescription::query()->create($data + [
                'prescription_number' => $this->numbers->allocate('RSL'),
                'status' => ExternalPrescription::STATUS_DITERIMA,
                'received_by' => $receivedBy,
            ]);

            $total = 0.0;

            foreach ($items as $drugId => $rincian) {
                $quantity = (float) $rincian['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $subtotal = $quantity * (float) $rincian['unit_price'];
                $total += $subtotal;

                $resep->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity' => $quantity,
                    'unit_price' => $rincian['unit_price'],
                ]);
            }

            $resep->update(['total_amount' => $total]);

            return $resep->fresh('items');
        });
    }

    public function dispense(ExternalPrescription $resep, User $actor): ExternalPrescription
    {
        if ($resep->status !== ExternalPrescription::STATUS_DITERIMA) {
            throw new PharmacyException('Resep luar ini sudah diserahkan atau dibatalkan.');
        }

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($resep, $actor, $depo): ExternalPrescription {
            $resep->load('items');

            foreach ($resep->items as $baris) {
                $this->ledger->issue(
                    drugId: $baris->drug_id,
                    locationId: $depo->id,
                    quantity: (float) $baris->quantity,
                    referenceType: 'external-prescription',
                    referenceId: $resep->id,
                    actor: $actor,
                );
            }

            $resep->update(['status' => ExternalPrescription::STATUS_DISERAHKAN, 'dispensed_by' => $actor->id, 'dispensed_at' => now()]);

            return $resep->refresh();
        });
    }

    public function cancel(ExternalPrescription $resep): ExternalPrescription
    {
        if ($resep->status !== ExternalPrescription::STATUS_DITERIMA) {
            throw new PharmacyException('Hanya resep luar yang belum diserahkan bisa dibatalkan.');
        }

        $resep->update(['status' => ExternalPrescription::STATUS_DIBATALKAN]);

        return $resep->refresh();
    }
}
