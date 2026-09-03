<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alur permintaan perbaikan: diajukan -> dikerjakan -> selesai, atau
 * diajukan -> ditolak. Status aset ikut berpindah begitu perbaikan mulai
 * dikerjakan (dalam-perbaikan) dan selesai (kembali aktif, kondisi
 * diperbarui) — status aset dan status permintaan sengaja disinkronkan di
 * sini, bukan diserahkan ke dua layar terpisah yang bisa lupa saling
 * memperbarui.
 */
class MaintenanceService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function report(Asset $asset, string $description, int $reportedBy): MaintenanceRequest
    {
        $adaAktif = MaintenanceRequest::query()
            ->where('asset_id', $asset->id)
            ->whereIn('status', [MaintenanceRequest::STATUS_DIAJUKAN, MaintenanceRequest::STATUS_DIKERJAKAN])
            ->exists();

        if ($adaAktif) {
            throw new AssetException("Aset {$asset->asset_number} sudah punya permintaan perbaikan yang masih berjalan.");
        }

        return MaintenanceRequest::query()->create([
            'request_number' => $this->numbers->allocate('PRB'),
            'asset_id' => $asset->id,
            'description' => $description,
            'status' => MaintenanceRequest::STATUS_DIAJUKAN,
            'reported_by' => $reportedBy,
        ]);
    }

    public function start(MaintenanceRequest $request, User $assignedTo): MaintenanceRequest
    {
        if (! $request->isPending()) {
            throw new AssetException('Permintaan ini sudah diproses sebelumnya.');
        }

        return DB::transaction(function () use ($request, $assignedTo): MaintenanceRequest {
            $request->update([
                'status' => MaintenanceRequest::STATUS_DIKERJAKAN,
                'assigned_to' => $assignedTo->id,
                'started_at' => now(),
            ]);

            $request->asset->update(['status' => Asset::STATUS_DALAM_PERBAIKAN]);

            return $request->refresh();
        });
    }

    public function complete(MaintenanceRequest $request, string $resolutionNotes, string $resultingCondition): MaintenanceRequest
    {
        if ($request->status !== MaintenanceRequest::STATUS_DIKERJAKAN) {
            throw new AssetException('Permintaan harus berstatus dikerjakan sebelum bisa diselesaikan.');
        }

        return DB::transaction(function () use ($request, $resolutionNotes, $resultingCondition): MaintenanceRequest {
            $request->update([
                'status' => MaintenanceRequest::STATUS_SELESAI,
                'resolution_notes' => $resolutionNotes,
                'completed_at' => now(),
            ]);

            $request->asset->update(['status' => Asset::STATUS_AKTIF, 'condition' => $resultingCondition]);

            return $request->refresh();
        });
    }

    public function reject(MaintenanceRequest $request, string $reason): MaintenanceRequest
    {
        if (! $request->isPending()) {
            throw new AssetException('Permintaan ini sudah diproses sebelumnya.');
        }

        $request->update(['status' => MaintenanceRequest::STATUS_DITOLAK, 'rejection_reason' => $reason]);

        return $request->refresh();
    }
}
