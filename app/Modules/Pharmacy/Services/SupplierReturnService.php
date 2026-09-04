<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\GoodsReceipt;
use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\SupplierReturn;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * retur_ke_suplier (DlgReturBeli) — mengembalikan barang yang sudah
 * diterima (mis. rusak/salah kirim) ke suplier. Mengurangi stok batch
 * terkait lewat StockLedger, kebalikan dari StockLedger::receive().
 */
class SupplierReturnService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, array{drug_id: int, batch_number: ?string, quantity: float, note: ?string}> $items */
    public function request(int $supplierId, ?GoodsReceipt $penerimaan, array $items, string $reason, User $actor): SupplierReturn
    {
        if ($items === []) {
            throw new PharmacyException('Retur harus berisi minimal satu obat/BHP.');
        }

        return DB::transaction(function () use ($supplierId, $penerimaan, $items, $reason, $actor): SupplierReturn {
            $retur = SupplierReturn::query()->create([
                'return_number' => $this->numbers->allocate('RET'),
                'supplier_id' => $supplierId,
                'goods_receipt_id' => $penerimaan?->id,
                'reason' => $reason,
                'returned_at' => now(),
                'returned_by' => $actor->id,
                'status' => SupplierReturn::STATUS_DIAJUKAN,
            ]);

            foreach ($items as $baris) {
                $retur->items()->create([
                    'drug_id' => $baris['drug_id'],
                    'batch_number' => $baris['batch_number'] ?? null,
                    'quantity' => $baris['quantity'],
                    'note' => $baris['note'] ?? null,
                ]);
            }

            return $retur->fresh('items');
        });
    }

    /** Menyelesaikan retur: barang benar-benar keluar gudang menuju suplier, stok berkurang. Hanya untuk baris yang batch_number-nya tercatat (bisa ditelusuri di stock_batches). */
    public function complete(SupplierReturn $retur, User $actor): SupplierReturn
    {
        if ($retur->status !== SupplierReturn::STATUS_DIAJUKAN) {
            throw new PharmacyException('Retur ini sudah selesai diproses.');
        }

        return DB::transaction(function () use ($retur, $actor): SupplierReturn {
            foreach ($retur->items as $baris) {
                if ($baris->batch_number === null) {
                    continue;
                }

                $batch = StockBatch::query()
                    ->where('drug_id', $baris->drug_id)
                    ->where('batch_number', $baris->batch_number)
                    ->first();

                if ($batch === null) {
                    continue;
                }

                $this->ledger->deductFromBatch(
                    batchId: $batch->id,
                    quantity: (float) $baris->quantity,
                    referenceType: 'supplier-return',
                    referenceId: $retur->id,
                    actor: $actor,
                    note: "Retur {$retur->return_number} ke suplier",
                );
            }

            $retur->update(['status' => SupplierReturn::STATUS_SELESAI]);

            return $retur->refresh();
        });
    }
}
