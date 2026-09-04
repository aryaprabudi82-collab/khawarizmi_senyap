<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockOpname;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * stok_opname_obat (DlgInputStok) — rekonsiliasi stok sistem vs hitung
 * fisik. Selisih (susut, salah catat, dst.) disesuaikan lewat movement
 * 'koreksi' saat opname diselesaikan, bukan langsung menimpa
 * quantity_on_hand — supaya tetap tercatat di buku besar seperti
 * perubahan stok lainnya.
 */
class StockOpnameService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** Membuka sesi opname untuk seluruh batch aktif (quantity_on_hand > 0) di satu lokasi, snapshot saldo sistem saat ini. */
    public function start(int $locationId, ?string $notes, int $createdBy): StockOpname
    {
        return DB::transaction(function () use ($locationId, $notes, $createdBy): StockOpname {
            $opname = StockOpname::query()->create([
                'opname_number' => $this->numbers->allocate('OPN'),
                'location_id' => $locationId,
                'status' => StockOpname::STATUS_DRAF,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            $batches = StockBatch::query()->where('location_id', $locationId)->where('quantity_on_hand', '>', 0)->get();

            foreach ($batches as $batch) {
                $opname->items()->create([
                    'batch_id' => $batch->id,
                    'system_quantity' => $batch->quantity_on_hand,
                ]);
            }

            return $opname->fresh('items');
        });
    }

    public function recordCount(StockOpname $opname, int $itemId, float $counted, ?string $note = null): void
    {
        if ($opname->status !== StockOpname::STATUS_DRAF) {
            throw new PharmacyException('Opname ini sudah diselesaikan.');
        }

        $opname->items()->whereKey($itemId)->update(['counted_quantity' => $counted, 'note' => $note]);
    }

    /** Menutup opname: tiap baris yang sudah dihitung dan berbeda dari saldo sistem disesuaikan lewat movement 'koreksi'. Baris yang belum dihitung dianggap sesuai (dilewati). */
    public function complete(StockOpname $opname, User $actor): StockOpname
    {
        if ($opname->status !== StockOpname::STATUS_DRAF) {
            throw new PharmacyException('Opname ini sudah diselesaikan.');
        }

        return DB::transaction(function () use ($opname, $actor): StockOpname {
            // load() paksa, bukan cache lama — recordCount() mengubah
            // counted_quantity lewat query terpisah setelah $opname mungkin
            // sudah pernah memuat relasi items-nya (lihat catatan yang sama
            // di PurchaseOrderService::refreshReceivingStatus()).
            $opname->load('items.batch');

            foreach ($opname->items as $baris) {
                $selisih = $baris->difference();

                if ($selisih === null || abs($selisih) < 0.0001) {
                    continue;
                }

                $batch = $baris->batch;
                $catatan = "Opname {$opname->opname_number}";

                if ($selisih > 0) {
                    $this->ledger->receive(
                        drugId: $batch->drug_id,
                        locationId: $batch->location_id,
                        batchNumber: $batch->batch_number,
                        quantity: $selisih,
                        expiryDate: $batch->expiry_date?->toDateString(),
                        costPrice: (float) $batch->cost_price,
                        actor: $actor,
                        note: $catatan,
                        kind: 'koreksi',
                    );
                } else {
                    $this->ledger->deductFromBatch(
                        batchId: $batch->id,
                        quantity: abs($selisih),
                        referenceType: 'stock-opname',
                        referenceId: $opname->id,
                        actor: $actor,
                        note: $catatan,
                        kind: 'koreksi',
                    );
                }
            }

            $opname->update(['status' => StockOpname::STATUS_SELESAI, 'completed_at' => now()]);

            return $opname->refresh();
        });
    }
}
