<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\Requisition;
use Illuminate\Support\Facades\DB;

/**
 * pengajuan_asetinventaris — permintaan aset/inventaris baru dari unit.
 * Beda dari Inventory/Kitchen\RequisitionService: tidak ada
 * fulfill()/stok yang dikeluarkan di sini, karena aset tidak berasal
 * dari stok yang sudah ada — permintaan disetujui lalu ditindaklanjuti
 * lewat PO (PurchaseOrderService::create() menandai requisition ini
 * 'selesai' begitu PO dibuat, lihat catatan di sana).
 */
class AssetRequisitionService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /** @param array<int, array{item_name: string, category_id: ?int, quantity: float}> $items */
    public function request(int $unitId, string $unitName, array $items, ?string $notes, int $requestedBy): Requisition
    {
        if ($items === []) {
            throw new AssetException('Pengajuan harus berisi minimal satu barang.');
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

            foreach ($items as $baris) {
                if ((float) $baris['quantity'] <= 0) {
                    continue;
                }

                $requisition->items()->create([
                    'item_name' => $baris['item_name'],
                    'category_id' => $baris['category_id'] ?? null,
                    'quantity_requested' => $baris['quantity'],
                ]);
            }

            return $requisition->fresh('items');
        });
    }

    public function approve(Requisition $requisition, int $decidedBy): Requisition
    {
        if (! $requisition->isPending()) {
            throw new AssetException('Pengajuan ini sudah diputuskan sebelumnya.');
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
            throw new AssetException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_DITOLAK,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $requisition->refresh();
    }
}
