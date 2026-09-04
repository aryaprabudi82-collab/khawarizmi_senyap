<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugRequisition;
use Illuminate\Support\Facades\DB;

/**
 * pengajuan_barang_medis — permintaan obat/BHP dari unit ke farmasi.
 * Pola sama dengan Inventory\Services\RequisitionService (non-medis):
 * diajukan -> disetujui/ditolak. "selesai" di sini dicapai lewat
 * PurchaseOrderService (PO dibuat dari pengajuan ini), bukan lewat
 * pengeluaran stok langsung — beda dari inventory karena Farmasi tidak
 * selalu punya stok siap kirim, sering harus dipesan dulu ke suplier.
 */
class DrugRequisitionService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /** @param array<int, float> $items drugId => quantity_requested */
    public function request(int $unitId, string $unitName, array $items, ?string $notes, int $requestedBy): DrugRequisition
    {
        if ($items === []) {
            throw new PharmacyException('Pengajuan harus berisi minimal satu obat/BHP.');
        }

        return DB::transaction(function () use ($unitId, $unitName, $items, $notes, $requestedBy): DrugRequisition {
            $pengajuan = DrugRequisition::query()->create([
                'requisition_number' => $this->numbers->allocate('PGJ'),
                'unit_id' => $unitId,
                'unit_name' => $unitName,
                'status' => DrugRequisition::STATUS_DIAJUKAN,
                'notes' => $notes,
                'requested_by' => $requestedBy,
            ]);

            foreach ($items as $drugId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $drugName = Drug::query()->whereKey($drugId)->value('name') ?? '—';

                $pengajuan->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => $drugName,
                    'quantity_requested' => $quantity,
                ]);
            }

            return $pengajuan;
        });
    }

    public function approve(DrugRequisition $pengajuan, int $decidedBy): DrugRequisition
    {
        if (! $pengajuan->isPending()) {
            throw new PharmacyException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        $pengajuan->update([
            'status' => DrugRequisition::STATUS_DISETUJUI,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
        ]);

        return $pengajuan->refresh();
    }

    public function reject(DrugRequisition $pengajuan, int $decidedBy, string $reason): DrugRequisition
    {
        if (! $pengajuan->isPending()) {
            throw new PharmacyException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        $pengajuan->update([
            'status' => DrugRequisition::STATUS_DITOLAK,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $pengajuan->refresh();
    }
}
