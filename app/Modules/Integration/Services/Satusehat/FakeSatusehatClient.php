<?php

namespace App\Modules\Integration\Services\Satusehat;

use Illuminate\Support\Str;

/**
 * Adapter palsu, dipakai saat kredensial OAuth2 SATUSEHAT belum ada (lokal,
 * uji otomatis). Selalu berhasil dan mengembalikan resource_id baru yang
 * stabil per (resourceType, existingId) supaya panggilan berulang terasa
 * seperti update sungguhan, bukan create baru tiap kali.
 */
class FakeSatusehatClient implements SatusehatClient
{
    public function putResource(string $resourceType, ?string $existingId, array $resource): array
    {
        $resourceId = $existingId ?? 'fake-' . Str::lower($resourceType) . '-' . Str::random(12);

        return [
            'success' => true,
            'resource_id' => $resourceId,
            'message' => $existingId === null ? 'Resource dibuat (palsu).' : 'Resource diperbarui (palsu).',
            'response' => $resource + ['id' => $resourceId, 'resourceType' => $resourceType],
        ];
    }

    public function organizationId(): string
    {
        return 'fake-organization-simrs-mandiri';
    }
}
