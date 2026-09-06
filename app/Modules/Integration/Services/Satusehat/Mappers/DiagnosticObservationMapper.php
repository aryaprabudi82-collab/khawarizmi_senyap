<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR Observation dari HASIL PENUNJANG
 * (satu_sehat_kirim_observation_lab dan observation_radiologi).
 *
 * Sengaja terpisah dari ObservationMapper yang menangani tanda-tanda vital.
 * Keduanya menghasilkan Observation, tapi hasil penunjang membawa dua hal
 * yang tidak dimiliki pengukuran tekanan darah: RENTANG RUJUKAN
 * laboratorium yang mengerjakannya, dan SPESIMEN yang diperiksa.
 *
 * RENTANG RUJUKAN IKUT DIKIRIM. "Hemoglobin 11,5 g/dL" tidak cukup untuk
 * ditafsirkan fasilitas lain: normal atau tidaknya bergantung pada rentang
 * laboratorium yang memeriksanya, dan rentang itu berbeda antar alat dan
 * antar populasi. Mengirim angkanya saja memaksa penerima menebak dengan
 * rentang laboratoriumnya sendiri.
 *
 * MENGEMBALIKAN NULL KALAU KODENYA BELUM DIPETAKAN — aturan yang sama
 * seperti seluruh mapper SATUSEHAT.
 */
class DiagnosticObservationMapper
{
    public function __construct(private readonly CodeMappingService $codes) {}

    /**
     * @param  array<int, string>  $specimenIds
     * @return array<string, mixed>|null null kalau kodenya belum dipetakan
     */
    public function build(
        stdClass $result,
        string $patientId,
        string $encounterId,
        string $mappingType,
        ?string $serviceRequestId = null,
        array $specimenIds = [],
    ): ?array {
        $kode = $this->codes->resolve($mappingType, $result->test_code);

        if ($kode === null) {
            return null;
        }

        $resource = [
            'resourceType' => 'Observation',
            // 'final' hanya boleh untuk hasil yang sudah diverifikasi —
            // penyaringannya ada di OrderContext, bukan di sini.
            'status' => 'final',
            'category' => [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                    'code' => $result->category === 'radiologi' ? 'imaging' : 'laboratory',
                    'display' => $result->category === 'radiologi' ? 'Imaging' : 'Laboratory',
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
            'effectiveDateTime' => Carbon::parse($result->entered_at ?? $result->resulted_at)->toIso8601String(),
            'issued' => Carbon::parse($result->verified_at ?? $result->resulted_at)->toIso8601String(),
        ];

        if ($serviceRequestId !== null) {
            $resource['basedOn'] = [['reference' => "ServiceRequest/{$serviceRequestId}"]];
        }

        if ($specimenIds !== []) {
            // FHIR hanya mengenal satu spesimen per Observation; yang dipakai
            // adalah bahan tempat pemeriksaan ini benar-benar dikerjakan.
            $resource['specimen'] = ['reference' => 'Specimen/' . reset($specimenIds)];
        }

        // Angka dikirim sebagai angka, teks sebagai teks. Angka yang dikirim
        // sebagai teks tidak bisa dibandingkan antar waktu maupun antar
        // fasilitas — justru alasan utama data ini dikirim.
        if ($result->result_numeric !== null) {
            $resource['valueQuantity'] = array_filter([
                'value' => (float) $result->result_numeric,
                'unit' => $result->unit,
                'system' => 'http://unitsofmeasure.org',
                'code' => $result->unit,
            ], fn ($v) => $v !== null && $v !== '');
        } elseif ($result->result_text !== null && $result->result_text !== '') {
            $resource['valueString'] = $result->result_text;
        }

        $rujukan = $this->referenceRange($result);

        if ($rujukan !== null) {
            $resource['referenceRange'] = [$rujukan];
        }

        if (! empty($result->is_abnormal)) {
            $resource['interpretation'] = [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation',
                    'code' => 'A',
                    'display' => 'Abnormal',
                ]],
            ]];
        }

        if (! empty($result->result_notes)) {
            $resource['note'] = [['text' => $result->result_notes]];
        }

        return $resource;
    }

    /**
     * Rentang rujukan laboratorium yang mengerjakannya — lihat catatan kelas.
     *
     * @return array<string, mixed>|null
     */
    private function referenceRange(stdClass $result): ?array
    {
        $rentang = [];

        if ($result->reference_low !== null) {
            $rentang['low'] = array_filter([
                'value' => (float) $result->reference_low,
                'unit' => $result->unit,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($result->reference_high !== null) {
            $rentang['high'] = array_filter([
                'value' => (float) $result->reference_high,
                'unit' => $result->unit,
            ], fn ($v) => $v !== null && $v !== '');
        }

        // Rentang naratif ("negatif", "tidak ditemukan kuman") sama sahnya
        // dengan rentang angka, dan untuk pemeriksaan kualitatif justru
        // satu-satunya bentuk yang ada.
        if (! empty($result->reference_text)) {
            $rentang['text'] = $result->reference_text;
        }

        return $rentang === [] ? null : $rentang;
    }
}
