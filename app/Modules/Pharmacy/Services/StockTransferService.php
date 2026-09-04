<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockTransfer;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * mutasi_barang (DlgMutasiBarang) — transfer stok antar lokasi, mis.
 * GUDANG (tempat barang hasil pengadaan diterima, lihat
 * GoodsReceiptService) ke DEPO-RJ (tempat resep dilayani). Tanpa ini,
 * stok hasil pengadaan tidak pernah bisa dipakai menyerahkan resep —
 * satu-satunya jalan barang berpindah antar lokasi farmasi.
 */
class StockTransferService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, array{batch_id: int, quantity: float}> $items */
    public function transfer(int $fromLocationId, int $toLocationId, array $items, ?string $notes, User $actor): StockTransfer
    {
        if ($fromLocationId === $toLocationId) {
            throw new PharmacyException('Lokasi asal dan tujuan tidak boleh sama.');
        }

        if ($items === []) {
            throw new PharmacyException('Mutasi harus berisi minimal satu obat/BHP.');
        }

        return DB::transaction(function () use ($fromLocationId, $toLocationId, $items, $notes, $actor): StockTransfer {
            $mutasi = StockTransfer::query()->create([
                'transfer_number' => $this->numbers->allocate('MUT'),
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'transferred_at' => now(),
                'transferred_by' => $actor->id,
                'notes' => $notes,
            ]);

            foreach ($items as $baris) {
                $quantity = (float) $baris['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $batchAsal = StockBatch::query()->whereKey($baris['batch_id'])->where('location_id', $fromLocationId)->firstOrFail();

                $mutasi->items()->create([
                    'drug_id' => $batchAsal->drug_id,
                    'batch_number' => $batchAsal->batch_number,
                    'quantity' => $quantity,
                ]);

                $this->ledger->deductFromBatch(
                    batchId: $batchAsal->id,
                    quantity: $quantity,
                    referenceType: 'stock-transfer',
                    referenceId: $mutasi->id,
                    actor: $actor,
                    note: "Mutasi {$mutasi->transfer_number} ke lokasi tujuan",
                    kind: 'mutasi-keluar',
                );

                $this->ledger->receive(
                    drugId: $batchAsal->drug_id,
                    locationId: $toLocationId,
                    batchNumber: $batchAsal->batch_number,
                    quantity: $quantity,
                    expiryDate: $batchAsal->expiry_date?->toDateString(),
                    costPrice: (float) $batchAsal->cost_price,
                    actor: $actor,
                    note: "Mutasi {$mutasi->transfer_number} dari lokasi asal",
                    kind: 'mutasi-masuk',
                    referenceType: 'stock-transfer',
                    referenceId: $mutasi->id,
                );
            }

            return $mutasi->fresh('items');
        });
    }
}
