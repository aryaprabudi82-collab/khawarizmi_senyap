<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\GoodsReceipt;
use App\Modules\Kitchen\Models\SupplierReturn;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * dapur_returbeli — mengembalikan barang ke suplier dapur. Lewat
 * StockLedger::issue() (source='retur-suplier', sudah terdaftar sejak
 * migrasi awal item A tapi belum pernah benar-benar dipanggil kode
 * manapun).
 */
class SupplierReturnService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, array{item_id: int, quantity: float, note: ?string}> $items */
    public function request(int $supplierId, ?GoodsReceipt $penerimaan, array $items, string $reason, User $actor): SupplierReturn
    {
        if ($items === []) {
            throw new KitchenException('Retur harus berisi minimal satu barang.');
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
                    'item_id' => $baris['item_id'],
                    'quantity' => $baris['quantity'],
                    'note' => $baris['note'] ?? null,
                ]);
            }

            return $retur->fresh('items');
        });
    }

    /** Menyelesaikan retur: barang benar-benar keluar gudang menuju suplier, stok berkurang. */
    public function complete(SupplierReturn $retur, User $actor): SupplierReturn
    {
        if ($retur->status !== SupplierReturn::STATUS_DIAJUKAN) {
            throw new KitchenException('Retur ini sudah selesai diproses.');
        }

        return DB::transaction(function () use ($retur, $actor): SupplierReturn {
            foreach ($retur->items as $baris) {
                $this->ledger->issue(
                    itemId: $baris->item_id,
                    quantity: (float) $baris->quantity,
                    source: 'retur-suplier',
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
