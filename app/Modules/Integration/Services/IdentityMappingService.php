<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\IdentityMapping;
use Illuminate\Support\Collection;

/**
 * Satu-satunya tempat ID internal dipetakan ke ID eksternal (SATUSEHAT,
 * BPJS) dan sebaliknya dicari. Dipakai baik oleh sinkronisasi otomatis
 * (Patient/Encounter/Condition) maupun pemetaan yang diisi manual lewat
 * layar admin (praktisi, lokasi/unit — SATUSEHAT mensyaratkan keduanya
 * sudah terdaftar lebih dulu di portal mereka, jadi ID-nya tidak dibuat
 * lewat API, cuma dicatat di sini).
 */
class IdentityMappingService
{
    public function find(string $targetSystem, string $resourceType, string $sourceContext, int $sourceId): ?IdentityMapping
    {
        return IdentityMapping::query()
            ->where('target_system', $targetSystem)
            ->where('resource_type', $resourceType)
            ->where('source_context', $sourceContext)
            ->where('source_id', $sourceId)
            ->first();
    }

    public function externalIdFor(string $targetSystem, string $resourceType, string $sourceContext, int $sourceId): ?string
    {
        return $this->find($targetSystem, $resourceType, $sourceContext, $sourceId)?->external_id;
    }

    /** Semua pemetaan satu jenis resource — dipakai layar admin menampilkan yang sudah/belum dipetakan. */
    public function allFor(string $targetSystem, string $resourceType, string $sourceContext): Collection
    {
        return IdentityMapping::query()
            ->where('target_system', $targetSystem)
            ->where('resource_type', $resourceType)
            ->where('source_context', $sourceContext)
            ->get()
            ->keyBy('source_id');
    }

    /** Dipakai setelah sinkronisasi berhasil — mencatat ID eksternal yang baru didapat/dikonfirmasi. */
    public function remember(
        string $targetSystem,
        string $resourceType,
        string $sourceContext,
        int $sourceId,
        string $externalId,
        ?array $meta = null,
    ): IdentityMapping {
        return IdentityMapping::query()->updateOrCreate(
            [
                'target_system' => $targetSystem,
                'resource_type' => $resourceType,
                'source_context' => $sourceContext,
                'source_id' => $sourceId,
            ],
            [
                'external_id' => $externalId,
                'external_meta' => $meta,
                'synced_at' => now(),
            ]
        );
    }

    /** Dipakai layar admin untuk memetakan praktisi/unit ke ID SATUSEHAT tanpa lewat API. */
    public function setManually(
        string $targetSystem,
        string $resourceType,
        string $sourceContext,
        int $sourceId,
        string $externalId,
        int $mappedBy,
    ): IdentityMapping {
        return IdentityMapping::query()->updateOrCreate(
            [
                'target_system' => $targetSystem,
                'resource_type' => $resourceType,
                'source_context' => $sourceContext,
                'source_id' => $sourceId,
            ],
            [
                'external_id' => $externalId,
                'mapped_by' => $mappedBy,
                'synced_at' => null,
            ]
        );
    }
}
