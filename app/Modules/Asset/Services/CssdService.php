<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\CssdCirculation;
use App\Modules\Asset\Models\CssdItem;
use App\Modules\Platform\Models\User;

/**
 * Alur sirkulasi CSSD: kotor -> diproses -> steril -> didistribusikan,
 * berurutan ketat — tidak ada jalan pintas (mis. langsung "steril" tanpa
 * lewat "diproses"), karena tiap tahap punya penanggung jawab dan waktu
 * sendiri yang harus bisa ditelusuri.
 */
class CssdService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function receive(CssdItem $item, ?int $unitId, string $unitName, User $recordedBy, ?string $notes = null): CssdCirculation
    {
        return CssdCirculation::query()->create([
            'circulation_number' => $this->numbers->allocate('CSSD'),
            'cssd_item_id' => $item->id,
            'unit_id' => $unitId,
            'unit_name' => $unitName,
            'status' => CssdCirculation::STATUS_KOTOR,
            'received_at' => now(),
            'notes' => $notes,
            'recorded_by' => $recordedBy->id,
        ]);
    }

    public function startProcessing(CssdCirculation $circulation): CssdCirculation
    {
        $this->assertStatus($circulation, CssdCirculation::STATUS_KOTOR, CssdCirculation::STATUS_DIPROSES);

        $circulation->update(['status' => CssdCirculation::STATUS_DIPROSES, 'processed_at' => now()]);

        return $circulation->refresh();
    }

    public function markSterile(CssdCirculation $circulation, string $sterilizationMethod): CssdCirculation
    {
        $this->assertStatus($circulation, CssdCirculation::STATUS_DIPROSES, CssdCirculation::STATUS_STERIL);

        $circulation->update([
            'status' => CssdCirculation::STATUS_STERIL,
            'sterilized_at' => now(),
            'sterilization_method' => $sterilizationMethod,
        ]);

        return $circulation->refresh();
    }

    public function distribute(CssdCirculation $circulation): CssdCirculation
    {
        $this->assertStatus($circulation, CssdCirculation::STATUS_STERIL, CssdCirculation::STATUS_DIDISTRIBUSIKAN);

        $circulation->update(['status' => CssdCirculation::STATUS_DIDISTRIBUSIKAN, 'distributed_at' => now()]);

        return $circulation->refresh();
    }

    private function assertStatus(CssdCirculation $circulation, string $expected, string $moveTo): void
    {
        if ($circulation->status !== $expected) {
            throw new AssetException(
                "Sirkulasi {$circulation->circulation_number} berstatus '{$circulation->status}', tidak bisa dipindah ke '{$moveTo}' dari sana — harus '{$expected}' lebih dulu."
            );
        }
    }
}
