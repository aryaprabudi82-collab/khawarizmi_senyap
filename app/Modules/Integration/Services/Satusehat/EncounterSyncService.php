<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\RegistrationContext;
use App\Modules\Integration\Services\Satusehat\Mappers\EncounterMapper;
use Carbon\Carbon;
use RuntimeException;

/**
 * Encounter SATUSEHAT mensyaratkan Patient sudah punya ID SATUSEHAT
 * (PatientSyncService dijalankan lebih dulu). Practitioner dan Location
 * tidak dibuat dari sini sama sekali — keduanya harus sudah dipetakan
 * manual lewat layar admin integrasi, karena SATUSEHAT mensyaratkan
 * praktisi/lokasi terdaftar lebih dulu di portal mereka, bukan dibuat lewat
 * API kunjungan. Kalau belum dipetakan, Encounter tetap dikirim tanpa
 * participant/location — bukan gagal keseluruhan — supaya satu praktisi
 * yang belum sempat dipetakan tidak memblokir seluruh sinkronisasi.
 */
class EncounterSyncService
{
    private const TARGET = 'satusehat';
    private const RESOURCE = 'encounter';
    private const CONTEXT = 'encounter';

    public function __construct(
        private readonly SatusehatClient $client,
        private readonly EncounterMapper $mapper,
        private readonly RegistrationContext $registrations,
        private readonly IdentityMappingService $mappings,
        private readonly OutboundMessageLedger $ledger,
    ) {}

    public function sync(int $registrationId): OutboundMessage
    {
        $registration = $this->registrations->find($registrationId);

        if ($registration === null) {
            throw new RuntimeException("Registrasi #{$registrationId} tidak ditemukan.");
        }

        $patientExternalId = $this->mappings->externalIdFor('satusehat', 'patient', 'identity', (int) $registration->patient_id);

        if ($patientExternalId === null) {
            throw new RuntimeException('Pasien belum disinkronkan ke SATUSEHAT. Sinkronkan pasien terlebih dahulu.');
        }

        $practitionerExternalId = $registration->practitioner_id !== null
            ? $this->mappings->externalIdFor('satusehat', 'practitioner', 'organization', (int) $registration->practitioner_id)
            : null;
        $locationExternalId = $this->mappings->externalIdFor('satusehat', 'location', 'organization', (int) $registration->unit_id);

        $existingId = $this->mappings->externalIdFor(self::TARGET, self::RESOURCE, self::CONTEXT, $registrationId);

        $resource = $this->mapper->build(
            $registration,
            $this->client->organizationId(),
            $patientExternalId,
            $practitionerExternalId,
            $locationExternalId,
        );

        $result = $this->client->putResource('Encounter', $existingId, $resource);

        if ($result['success']) {
            $this->mappings->remember(self::TARGET, self::RESOURCE, self::CONTEXT, $registrationId, (string) $result['resource_id']);
        }

        return $this->ledger->record(
            targetSystem: self::TARGET,
            resourceType: self::RESOURCE,
            sourceContext: self::CONTEXT,
            sourceId: $registrationId,
            sourceEventAt: Carbon::parse($registration->registered_at),
            requestPayload: $resource,
            success: $result['success'],
            responsePayload: $result['response'],
            externalReference: $result['resource_id'],
            errorMessage: $result['success'] ? null : $result['message'],
        );
    }
}
