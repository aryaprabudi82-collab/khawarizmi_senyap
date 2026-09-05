<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetTransfer;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * inventaris_sirkulasi — mutasi SATU aset dari satu ruang/lokasi ke
 * lokasi lain. asset.assets.location_id adalah cache lokasi terkini
 * (diperbarui tiap perpindahan); riwayat lengkapnya di
 * asset_transfers, satu baris per perpindahan.
 */
class AssetTransferService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function transfer(Asset $asset, int $toLocationId, User $actor, ?string $notes = null): AssetTransfer
    {
        if ($asset->location_id === $toLocationId) {
            throw new AssetException('Aset ini sudah berada di lokasi tujuan.');
        }

        return DB::transaction(function () use ($asset, $toLocationId, $actor, $notes): AssetTransfer {
            $mutasi = AssetTransfer::query()->create([
                'transfer_number' => $this->numbers->allocate('MUT'),
                'asset_id' => $asset->id,
                'from_location_id' => $asset->location_id,
                'to_location_id' => $toLocationId,
                'transferred_at' => now(),
                'transferred_by' => $actor->id,
                'notes' => $notes,
            ]);

            $asset->update(['location_id' => $toLocationId]);

            return $mutasi->fresh(['asset', 'fromLocation', 'toLocation']);
        });
    }

    /** Riwayat lengkap perpindahan satu aset, terbaru dulu. */
    public function history(Asset $asset): \Illuminate\Support\Collection
    {
        return $asset->transfers()->with(['fromLocation', 'toLocation'])->latest('transferred_at')->get();
    }
}
