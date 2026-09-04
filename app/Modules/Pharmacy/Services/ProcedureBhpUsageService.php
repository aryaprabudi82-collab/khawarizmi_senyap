<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\ProcedureBhpUsage;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * penggunaan_bhp_ok — BHP dipakai saat tindakan di OK/VK. Dicatat
 * begitu dipakai (bukan diminta dulu lalu dikeluarkan seperti
 * WardStockRequest) — stok langsung terpotong saat baris disimpan,
 * pola sama dengan resep_obat yang memotong stok begitu diserahkan.
 */
class ProcedureBhpUsageService
{
    private const DEPO_CODE = 'DEPO-RJ';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, float> $items drugId => quantity */
    public function record(array $data, array $items, User $actor): ProcedureBhpUsage
    {
        if ($items === []) {
            throw new PharmacyException('Penggunaan BHP harus berisi minimal satu obat/BHP.');
        }

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($data, $items, $actor, $depo): ProcedureBhpUsage {
            $penggunaan = ProcedureBhpUsage::query()->create($data + [
                'usage_number' => $this->numbers->allocate('BHP'),
                'used_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            foreach ($items as $drugId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $penggunaan->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity' => $quantity,
                ]);

                $this->ledger->issue(
                    drugId: $drugId,
                    locationId: $depo->id,
                    quantity: $quantity,
                    referenceType: 'procedure-bhp-usage',
                    referenceId: $penggunaan->id,
                    actor: $actor,
                );
            }

            return $penggunaan->fresh('items');
        });
    }
}
