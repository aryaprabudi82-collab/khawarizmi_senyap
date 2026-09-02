<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use stdClass;

/** Menyusun resource FHIR Condition dari clinical.v_encounter_diagnosis. */
class ConditionMapper
{
    private const VERIFICATION_MAP = [
        'suspek' => 'unconfirmed',
        'kerja' => 'provisional',
        'definitif' => 'confirmed',
    ];

    public function build(stdClass $diagnosis, string $patientId, string $encounterId): array
    {
        return [
            'resourceType' => 'Condition',
            'clinicalStatus' => [
                'coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active']],
            ],
            'verificationStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                    'code' => self::VERIFICATION_MAP[$diagnosis->certainty] ?? 'unconfirmed',
                ]],
            ],
            'category' => [[
                'coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'encounter-diagnosis']],
            ]],
            'code' => [
                'coding' => [['system' => 'http://hl7.org/fhir/sid/icd-10', 'code' => $diagnosis->code, 'display' => $diagnosis->display]],
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'recordedDate' => \Carbon\Carbon::parse($diagnosis->diagnosed_at)->toDateString(),
        ];
    }
}
