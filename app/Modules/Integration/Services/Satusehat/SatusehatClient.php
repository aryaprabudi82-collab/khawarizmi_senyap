<?php

namespace App\Modules\Integration\Services\Satusehat;

/**
 * Kontrak adapter SATUSEHAT (Platform Kemenkes, berbasis FHIR R4). Wave 1
 * hanya butuh satu operasi generik: kirim resource FHIR sebagai create
 * (POST) kalau belum pernah disinkronkan, atau update (PUT) kalau sudah
 * punya ID SATUSEHAT — pemanggil (PatientSyncService dkk.) yang menentukan
 * bentuk resource-nya lewat Mapper masing-masing.
 */
interface SatusehatClient
{
    /**
     * @param array<string, mixed> $resource Bentuk JSON resource FHIR, tanpa 'id' kalau membuat baru.
     * @return array{success: bool, resource_id: ?string, message: string, response: array<string, mixed>}
     */
    public function putResource(string $resourceType, ?string $existingId, array $resource): array;

    /** ID Organization SATUSEHAT milik RS ini sendiri — sudah tetap sejak registrasi faskes, bukan dibuat lewat API. */
    public function organizationId(): string;
}
