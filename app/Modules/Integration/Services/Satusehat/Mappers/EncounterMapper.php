<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use stdClass;

/**
 * Menyusun resource FHIR Encounter dari encounter.v_registration_summary.
 * Patient/Practitioner/Location harus sudah punya ID SATUSEHAT lebih dulu
 * (dicari lewat IdentityMappingService oleh EncounterSyncService) — mapper
 * ini murni transformasi bentuk, tidak menyentuh basis data.
 */
class EncounterMapper
{
    private const STATUS_MAP = [
        'terdaftar' => 'arrived',
        'dipanggil' => 'arrived',
        'dilayani' => 'in-progress',
        'selesai' => 'finished',
        'tidak-hadir' => 'cancelled',
    ];

    public function build(
        stdClass $registration,
        string $organizationId,
        string $patientId,
        ?string $practitionerId,
        ?string $locationId,
    ): array {
        $isInpatient = $registration->care_type === 'ranap';

        $resource = [
            'resourceType' => 'Encounter',
            'status' => self::STATUS_MAP[$registration->status] ?? 'unknown',
            'class' => $isInpatient
                ? ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP', 'display' => 'inpatient encounter']
                : ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB', 'display' => 'ambulatory'],
            'identifier' => [[
                'system' => "http://sys-ids.kemkes.go.id/encounter/{$organizationId}",
                'value' => $registration->registration_number,
            ]],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'period' => ['start' => \Carbon\Carbon::parse($registration->registered_at)->toAtomString()],
            'serviceProvider' => ['reference' => "Organization/{$organizationId}"],
        ];

        if ($practitionerId !== null) {
            $resource['participant'] = [[
                'type' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType', 'code' => 'ATND']]]],
                'individual' => ['reference' => "Practitioner/{$practitionerId}"],
            ]];
        }

        if ($locationId !== null) {
            $resource['location'] = [['location' => ['reference' => "Location/{$locationId}"]]];
        }

        return $resource;
    }
}
