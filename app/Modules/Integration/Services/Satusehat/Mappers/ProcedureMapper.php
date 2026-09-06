<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use stdClass;

/**
 * Menyusun resource FHIR Procedure dari clinical.v_procedure_charge
 * (satu_sehat_kirim_procedure).
 *
 * MENGEMBALIKAN NULL KALAU KODENYA BELUM DIPETAKAN. Tindakan tanpa kode
 * SNOMED yang benar tidak bisa ditafsirkan fasilitas lain, dan mengirimnya
 * dengan kode tebakan berarti mencatatkan tindakan yang berbeda dari yang
 * sungguh dilakukan pada riwayat nasional pasien.
 */
class ProcedureMapper
{
    public function __construct(private readonly CodeMappingService $codes) {}

    /**
     * @return array<string, mixed>|null null kalau kodenya belum dipetakan
     */
    public function build(stdClass $procedure, string $patientId, string $encounterId, string $mappingType = 'tindakan-ralan'): ?array
    {
        $kode = $this->codes->resolve($mappingType, $procedure->service_code);

        if ($kode === null) {
            return null;
        }

        return [
            'resourceType' => 'Procedure',
            'status' => 'completed',
            'code' => [
                'coding' => [[
                    'system' => SatusehatCodeMapping::URI[$kode['system']] ?? null,
                    'code' => $kode['code'],
                    'display' => $kode['display'] ?? $procedure->service_name,
                ]],
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'performedDateTime' => \Carbon\Carbon::parse($procedure->performed_at)->toIso8601String(),
        ];
    }
}
