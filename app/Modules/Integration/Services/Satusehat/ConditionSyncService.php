<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\DiagnosisContext;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\Satusehat\Mappers\ConditionMapper;
use Carbon\Carbon;
use RuntimeException;

/** Condition SATUSEHAT mensyaratkan Patient dan Encounter sudah tersinkron lebih dulu. */
class ConditionSyncService
{
    private const TARGET = 'satusehat';
    private const RESOURCE = 'condition';
    private const CONTEXT = 'clinical';

    public function __construct(
        private readonly SatusehatClient $client,
        private readonly ConditionMapper $mapper,
        private readonly DiagnosisContext $diagnoses,
        private readonly IdentityMappingService $mappings,
        private readonly OutboundMessageLedger $ledger,
    ) {}

    /** Diagnosis utama satu kunjungan. Diagnosis sekunder bisa dikirim lewat overload lain kalau dibutuhkan nanti. */
    public function syncPrimary(int $registrationId): OutboundMessage
    {
        $diagnosis = $this->diagnoses->primaryFor($registrationId);

        if ($diagnosis === null) {
            throw new RuntimeException("Kunjungan #{$registrationId} belum punya diagnosis utama.");
        }

        $patientExternalId = $this->mappings->externalIdFor('satusehat', 'patient', 'identity', (int) $diagnosis->patient_id);
        $encounterExternalId = $this->mappings->externalIdFor('satusehat', 'encounter', 'encounter', $registrationId);

        if ($patientExternalId === null || $encounterExternalId === null) {
            throw new RuntimeException('Pasien dan kunjungan harus sudah disinkronkan ke SATUSEHAT sebelum mengirim diagnosis.');
        }

        // clinical.v_encounter_diagnosis tidak menerbitkan id baris diagnosis
        // (bukan kontrak yang dibutuhkan konsumen lain) — dipetakan per
        // kunjungan, konsisten dengan syncPrimary() yang hanya menangani satu
        // diagnosis utama per kunjungan untuk Wave 1 ini.
        $sourceId = $registrationId;
        $existingId = $this->mappings->externalIdFor(self::TARGET, self::RESOURCE, self::CONTEXT, $sourceId);

        $resource = $this->mapper->build($diagnosis, $patientExternalId, $encounterExternalId);

        $result = $this->client->putResource('Condition', $existingId, $resource);

        if ($result['success']) {
            $this->mappings->remember(self::TARGET, self::RESOURCE, self::CONTEXT, $sourceId, (string) $result['resource_id']);
        }

        return $this->ledger->record(
            targetSystem: self::TARGET,
            resourceType: self::RESOURCE,
            sourceContext: self::CONTEXT,
            sourceId: $sourceId,
            sourceEventAt: Carbon::parse($diagnosis->diagnosed_at),
            requestPayload: $resource,
            success: $result['success'],
            responsePayload: $result['response'],
            externalReference: $result['resource_id'],
            errorMessage: $result['success'] ? null : $result['message'],
        );
    }
}
