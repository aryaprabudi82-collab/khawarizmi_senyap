<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Models\WardStockRequest;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * pengeluaran_stok_apotek + pengambilan_utd — permintaan stok
 * ruangan/departemen (bukan untuk pasien tertentu). UTD diperlakukan
 * sebagai salah satu unit tujuan biasa, bukan tabel/gerbang sendiri
 * (lihat catatan migrasi). Dikeluarkan dari DEPO-RJ lewat
 * StockLedger::issue() (FEFO, guard atomik) — sama dengan resep_obat.
 */
class WardStockRequestService
{
    private const DEPO_CODE = 'DEPO-RJ';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, float> $items drugId => quantity_requested */
    public function request(int $unitId, string $unitName, array $items, ?string $notes, int $requestedBy): WardStockRequest
    {
        if ($items === []) {
            throw new PharmacyException('Permintaan harus berisi minimal satu obat/BHP.');
        }

        return DB::transaction(function () use ($unitId, $unitName, $items, $notes, $requestedBy): WardStockRequest {
            $permintaan = WardStockRequest::query()->create([
                'request_number' => $this->numbers->allocate('WRD'),
                'unit_id' => $unitId,
                'unit_name' => $unitName,
                'status' => WardStockRequest::STATUS_DIAJUKAN,
                'notes' => $notes,
                'requested_by' => $requestedBy,
            ]);

            foreach ($items as $drugId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $permintaan->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity_requested' => $quantity,
                ]);
            }

            return $permintaan->fresh('items');
        });
    }

    public function reject(WardStockRequest $permintaan, string $reason): WardStockRequest
    {
        if (! $permintaan->isPending()) {
            throw new PharmacyException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $permintaan->update(['status' => WardStockRequest::STATUS_DITOLAK, 'rejection_reason' => $reason]);

        return $permintaan->refresh();
    }

    /** Mengeluarkan seluruh baris sekaligus. Semua-atau-tidak-sama-sekali: satu baris gagal (stok kurang), seluruhnya dibatalkan. */
    public function issue(WardStockRequest $permintaan, User $actor): WardStockRequest
    {
        if (! $permintaan->isPending()) {
            throw new PharmacyException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($permintaan, $actor, $depo): WardStockRequest {
            $permintaan->load('items');

            foreach ($permintaan->items as $baris) {
                $this->ledger->issue(
                    drugId: $baris->drug_id,
                    locationId: $depo->id,
                    quantity: (float) $baris->quantity_requested,
                    referenceType: 'ward-stock-request',
                    referenceId: $permintaan->id,
                    actor: $actor,
                );

                $baris->update(['quantity_issued' => $baris->quantity_requested]);
            }

            $permintaan->update(['status' => WardStockRequest::STATUS_DIKELUARKAN, 'issued_by' => $actor->id, 'issued_at' => now()]);

            return $permintaan->refresh();
        });
    }
}
