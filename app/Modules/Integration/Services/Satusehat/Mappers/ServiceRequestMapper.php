<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR ServiceRequest — PERMINTAAN pemeriksaan penunjang
 * (satu_sehat_kirim_servicerequest_lab dan servicerequest_radiologi).
 *
 * ServiceRequest adalah pangkal rantai penunjang: Specimen diambil UNTUK
 * permintaan ini, Observation adalah jawabannya, dan DiagnosticReport
 * merangkumnya. Tanpa ServiceRequest yang diterima lebih dulu, ketiganya
 * menggantung tanpa alasan klinis — pemeriksaan yang seolah dikerjakan
 * tanpa ada yang meminta.
 *
 * MENGEMBALIKAN NULL KALAU KODENYA BELUM DIPETAKAN, aturan yang sama
 * seperti seluruh mapper SATUSEHAT: menebak kode LOINC berarti mengirim
 * permintaan pemeriksaan yang salah arti ke platform nasional.
 */
class ServiceRequestMapper
{
    public function __construct(private readonly CodeMappingService $codes) {}

    /**
     * @return array<string, mixed>|null null kalau kodenya belum dipetakan
     */
    public function build(
        stdClass $result,
        string $patientId,
        string $encounterId,
        string $practitionerId,
        string $mappingType,
    ): ?array {
        $kode = $this->codes->resolve($mappingType, $result->test_code);

        if ($kode === null) {
            return null;
        }

        $resource = [
            'resourceType' => 'ServiceRequest',
            // Permintaan yang hasilnya sudah terbit sudah selesai dikerjakan;
            // melaporkannya masih 'active' membuat fasilitas lain mengira
            // pemeriksaannya belum ada jawabannya.
            'status' => 'completed',
            'intent' => 'original-order',
            'priority' => 'routine',
            'category' => [[
                'coding' => [[
                    'system' => 'http://snomed.info/sct',
                    'code' => $result->category === 'radiologi' ? '363679005' : '108252007',
                    'display' => $result->category === 'radiologi'
                        ? 'Imaging'
                        : 'Laboratory procedure',
                ]],
            ]],
            'code' => [
                'coding' => [[
                    'system' => SatusehatCodeMapping::URI[$kode['system']] ?? null,
                    'code' => $kode['code'],
                    'display' => $kode['display'] ?? $result->test_name,
                ]],
                'text' => $result->test_name,
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'occurrenceDateTime' => Carbon::parse($result->requested_at)->toIso8601String(),
            'authoredOn' => Carbon::parse($result->requested_at)->toIso8601String(),
            'requester' => [
                'reference' => "Practitioner/{$practitionerId}",
                'display' => $result->requesting_practitioner_name,
            ],
        ];

        // Keterangan klinis dari dokter perujuk ikut dikirim: itu yang
        // membuat hasil bisa ditafsirkan fasilitas lain — angka tanpa alasan
        // pemeriksaannya jauh lebih sedikit artinya.
        if (! empty($result->clinical_notes)) {
            $resource['reasonCode'] = [['text' => $result->clinical_notes]];
        }

        return $resource;
    }
}
