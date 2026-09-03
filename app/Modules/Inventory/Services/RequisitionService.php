<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Requisition;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alur permintaan barang dari unit: diajukan -> disetujui/ditolak ->
 * (kalau disetujui) selesai setelah stok benar-benar dikeluarkan.
 * Persetujuan dan pengeluaran stok sengaja dua langkah terpisah — unit
 * gudang bisa menyetujui permintaan lebih dulu lalu menyiapkan barangnya,
 * tanpa stok langsung berkurang di titik persetujuan.
 */
class RequisitionService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, float> $items itemId => quantity_requested */
    public function request(int $unitId, string $unitName, array $items, ?string $notes, int $requestedBy): Requisition
    {
        if ($items === []) {
            throw new InventoryException('Permintaan harus berisi minimal satu barang.');
        }

        return DB::transaction(function () use ($unitId, $unitName, $items, $notes, $requestedBy): Requisition {
            $requisition = Requisition::query()->create([
                'requisition_number' => $this->numbers->allocate('PGJ'),
                'unit_id' => $unitId,
                'unit_name' => $unitName,
                'status' => Requisition::STATUS_DIAJUKAN,
                'notes' => $notes,
                'requested_by' => $requestedBy,
            ]);

            foreach ($items as $itemId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $requisition->items()->create(['item_id' => $itemId, 'quantity_requested' => $quantity]);
            }

            return $requisition;
        });
    }

    public function approve(Requisition $requisition, int $decidedBy): Requisition
    {
        if (! $requisition->isPending()) {
            throw new InventoryException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_DISETUJUI,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
        ]);

        return $requisition->refresh();
    }

    public function reject(Requisition $requisition, int $decidedBy, string $reason): Requisition
    {
        if (! $requisition->isPending()) {
            throw new InventoryException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_DITOLAK,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $requisition->refresh();
    }

    /** Mengeluarkan stok untuk seluruh baris sekaligus. Semua-atau-tidak-sama-sekali: gagal satu baris, seluruhnya dibatalkan. */
    public function fulfill(Requisition $requisition, User $actor): Requisition
    {
        if ($requisition->status !== Requisition::STATUS_DISETUJUI) {
            throw new InventoryException('Permintaan harus disetujui lebih dulu sebelum barangnya dikeluarkan.');
        }

        return DB::transaction(function () use ($requisition, $actor): Requisition {
            foreach ($requisition->items as $baris) {
                $this->ledger->issue(
                    itemId: $baris->item_id,
                    quantity: (float) $baris->quantity_requested,
                    source: 'permintaan-unit',
                    referenceType: 'requisition',
                    referenceId: $requisition->id,
                    actor: $actor,
                );

                $baris->update(['quantity_issued' => $baris->quantity_requested]);
            }

            $requisition->update(['status' => Requisition::STATUS_SELESAI]);

            return $requisition->refresh();
        });
    }
}
