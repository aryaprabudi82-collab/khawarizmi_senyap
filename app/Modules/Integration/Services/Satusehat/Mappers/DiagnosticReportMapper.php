<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR DiagnosticReport — LEMBAR HASIL yang merangkum
 * seluruh butir satu permintaan (satu_sehat_kirim_diagnosticreport_lab dan
 * diagnosticreport_radiologi).
 *
 * DiagnosticReport adalah ujung rantai penunjang, dan ia tidak memuat nilai
 * apa pun sendiri: ia MENUNJUK Observation yang sudah diterima lebih dulu.
 * Karena itu ia tidak boleh disusun sebelum Observation-nya berhasil
 * dikirim — laporan yang menunjuk hasil yang tidak ada di platform nasional
 * adalah lembar hasil kosong yang tampak lengkap.
 *
 * KODE LEMBARNYA MEMAKAI KATEGORI, BUKAN KODE PEMERIKSAAN. Satu permintaan
 * bisa memuat sepuluh pemeriksaan berbeda; memilih salah satu kodenya
 * sebagai kode lembar akan membuat sembilan sisanya seolah bagian dari
 * pemeriksaan yang bukan miliknya.
 */
class DiagnosticReportMapper
{
    /** Kode LOINC untuk jenis lembar hasil, bukan untuk pemeriksaannya. */
    private const KATEGORI = [
        'lab' => ['LAB', 'Laboratory', '11502-2', 'Laboratory report'],
        'radiologi' => ['RAD', 'Radiology', '18748-4', 'Diagnostic imaging study'],
        'pa' => ['PAT', 'Pathology', '11526-1', 'Pathology study'],
    ];

    /**
     * @param  array<int, string>  $observationIds
     * @param  array<int, string>  $specimenIds
     * @param  array<int, string>  $serviceRequestIds
     * @return array<string, mixed>
     */
    public function build(
        stdClass $header,
        string $patientId,
        string $encounterId,
        array $observationIds,
        array $specimenIds = [],
        array $serviceRequestIds = [],
    ): array {
        [$kodeKategori, $namaKategori, $kodeLoinc, $namaLoinc] =
            self::KATEGORI[$header->category] ?? self::KATEGORI['lab'];

        $resource = [
            'resourceType' => 'DiagnosticReport',
            'status' => 'final',
            'category' => [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/v2-0074',
                    'code' => $kodeKategori,
                    'display' => $namaKategori,
                ]],
            ]],
            'code' => [
                'coding' => [[
                    'system' => 'http://loinc.org',
                    'code' => $kodeLoinc,
                    'display' => $namaLoinc,
                ]],
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'effectiveDateTime' => Carbon::parse($header->resulted_at ?? $header->requested_at)->toIso8601String(),
            // 'issued' adalah saat hasil DINYATAKAN SAH, yakni saat
            // diverifikasi — bukan saat angkanya diketik.
            'issued' => Carbon::parse($header->verified_at ?? $header->resulted_at)->toIso8601String(),
            'result' => array_map(
                fn (string $id) => ['reference' => "Observation/{$id}"],
                array_values($observationIds)
            ),
        ];

        if ($serviceRequestIds !== []) {
            $resource['basedOn'] = array_map(
                fn (string $id) => ['reference' => "ServiceRequest/{$id}"],
                array_values($serviceRequestIds)
            );
        }

        if ($specimenIds !== []) {
            $resource['specimen'] = array_map(
                fn (string $id) => ['reference' => "Specimen/{$id}"],
                array_values($specimenIds)
            );
        }

        // Nama pemverifikasi ikut dikirim: lembar hasil penunjang adalah
        // pernyataan seseorang, bukan keluaran alat tanpa penanggung jawab.
        if (! empty($header->verified_by_name)) {
            $resource['resultsInterpreter'] = [['display' => $header->verified_by_name]];
        }

        return $resource;
    }
}
