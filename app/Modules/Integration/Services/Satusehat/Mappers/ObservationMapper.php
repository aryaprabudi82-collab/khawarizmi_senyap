<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use stdClass;

/**
 * Menyusun resource FHIR Observation dari clinical.observations
 * (satu_sehat_kirim_observationttv dan observation lab/radiologi).
 *
 * MENGEMBALIKAN NULL KALAU KODENYA BELUM DIPETAKAN. Observation tanpa
 * kode LOINC yang benar akan diterima SATUSEHAT sebagai pengukuran yang
 * tidak diketahui artinya — lebih buruk daripada tidak mengirim sama
 * sekali, karena data itu ikut terbaca fasilitas lain yang merawat pasien
 * yang sama dan tidak bisa mereka tafsirkan.
 *
 * NILAI DIKIRIM SESUAI BENTUKNYA: angka sebagai valueQuantity berikut
 * satuannya, teks sebagai valueString. Mengirim angka sebagai teks membuat
 * nilainya tidak bisa dibandingkan antar waktu maupun antar fasilitas —
 * yang justru alasan utama data ini dikirim ke platform nasional.
 */
class ObservationMapper
{
    public function __construct(private readonly CodeMappingService $codes) {}

    /**
     * @return array<string, mixed>|null null kalau kodenya belum dipetakan
     */
    public function build(stdClass $observation, string $patientId, string $encounterId, string $mappingType = 'lab'): ?array
    {
        $kode = $this->codes->resolve($mappingType, $observation->code);

        if ($kode === null) {
            return null;
        }

        $resource = [
            'resourceType' => 'Observation',
            'status' => 'final',
            'category' => [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                    'code' => $mappingType === 'lab' ? 'laboratory' : 'vital-signs',
                ]],
            ]],
            'code' => [
                'coding' => [[
                    'system' => SatusehatCodeMapping::URI[$kode['system']] ?? null,
                    'code' => $kode['code'],
                    'display' => $kode['display'] ?? $observation->display,
                ]],
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'effectiveDateTime' => \Carbon\Carbon::parse($observation->observed_at)->toIso8601String(),
        ];

        // Angka dikirim sebagai angka, teks sebagai teks — lihat catatan kelas.
        if ($observation->value_numeric !== null) {
            $resource['valueQuantity'] = [
                'value' => (float) $observation->value_numeric,
                'unit' => $observation->unit,
                'system' => 'http://unitsofmeasure.org',
            ];
        } elseif ($observation->value_text !== null && $observation->value_text !== '') {
            $resource['valueString'] = $observation->value_text;
        }

        // Penanda abnormal ikut dikirim: nilai di luar rentang normal adalah
        // informasi klinis tersendiri, bukan turunan yang bisa dihitung ulang
        // fasilitas lain tanpa tahu rentang rujukan laboratorium kita.
        if (! empty($observation->is_abnormal)) {
            $resource['interpretation'] = [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation',
                    'code' => 'A',
                    'display' => 'Abnormal',
                ]],
            ]];
        }

        return $resource;
    }
}
