<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\PatientContext;
use App\Modules\Integration\Services\Satusehat\Mappers\PatientMapper;
use Carbon\Carbon;
use RuntimeException;

class PatientSyncService
{
    private const TARGET = 'satusehat';
    private const RESOURCE = 'patient';
    private const CONTEXT = 'identity';

    public function __construct(
        private readonly SatusehatClient $client,
        private readonly PatientMapper $mapper,
        private readonly PatientContext $patients,
        private readonly IdentityMappingService $mappings,
        private readonly OutboundMessageLedger $ledger,
    ) {}

    public function sync(int $patientId): OutboundMessage
    {
        $patient = $this->patients->find($patientId);

        if ($patient === null) {
            throw new RuntimeException("Pasien #{$patientId} tidak ditemukan.");
        }

        $existingId = $this->mappings->externalIdFor(self::TARGET, self::RESOURCE, self::CONTEXT, $patientId);
        $resource = $this->mapper->build($patient);

        $result = $this->client->putResource('Patient', $existingId, $resource);

        if ($result['success']) {
            $this->mappings->remember(self::TARGET, self::RESOURCE, self::CONTEXT, $patientId, (string) $result['resource_id']);
        }

        return $this->ledger->record(
            targetSystem: self::TARGET,
            resourceType: self::RESOURCE,
            sourceContext: self::CONTEXT,
            sourceId: $patientId,
            sourceEventAt: Carbon::parse($patient->updated_at),
            requestPayload: $resource,
            success: $result['success'],
            responsePayload: $result['response'],
            externalReference: $result['resource_id'],
            errorMessage: $result['success'] ? null : $result['message'],
        );
    }
}
