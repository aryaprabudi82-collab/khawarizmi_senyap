<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use stdClass;

/**
 * Menyusun resource FHIR AllergyIntolerance dari clinical.allergies
 * (satu_sehat_kirim_allergy_intolerance).
 *
 * ALERGI SENGAJA TIDAK MENUNTUT PEMETAAN KODE. Alergi dicatat sebagai teks
 * bebas (nama zat), karena memaksa petugas memilih dari daftar kode saat
 * pasien menyebut alergi akan membuat alergi yang tidak ada di daftar
 * TIDAK TERCATAT SAMA SEKALI — dan alergi yang hilang jauh lebih berbahaya
 * daripada alergi yang kodenya kurang presisi. SATUSEHAT menerima
 * CodeableConcept dengan text saja untuk kasus ini.
 *
 * Derajat keparahan dan status dipetakan ke nilai FHIR yang setara; yang
 * tidak dikenali dikirim apa adanya sebagai teks alih-alih ditebak.
 */
class AllergyIntoleranceMapper
{
    private const KATEGORI = [
        'obat' => 'medication',
        'makanan' => 'food',
        'lingkungan' => 'environment',
        'lainnya' => null,
    ];

    private const KEPARAHAN = [
        'ringan' => 'mild',
        'sedang' => 'moderate',
        'berat' => 'severe',
    ];

    /** @return array<string, mixed> */
    public function build(stdClass $allergy, string $patientId): array
    {
        $resource = [
            'resourceType' => 'AllergyIntolerance',
            'clinicalStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                    'code' => ($allergy->status ?? 'aktif') === 'aktif' ? 'active' : 'inactive',
                ]],
            ],
            'verificationStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                    'code' => 'confirmed',
                ]],
            ],
            // Teks bebas, sengaja — lihat catatan kelas.
            'code' => ['text' => $allergy->substance],
            'patient' => ['reference' => "Patient/{$patientId}"],
            'recordedDate' => \Carbon\Carbon::parse($allergy->recorded_at)->toIso8601String(),
        ];

        $kategori = self::KATEGORI[$allergy->category ?? ''] ?? null;

        if ($kategori !== null) {
            $resource['category'] = [$kategori];
        }

        if (! empty($allergy->reaction)) {
            $reaksi = ['manifestation' => [['text' => $allergy->reaction]]];

            $keparahan = self::KEPARAHAN[$allergy->severity ?? ''] ?? null;

            if ($keparahan !== null) {
                $reaksi['severity'] = $keparahan;
            }

            $resource['reaction'] = [$reaksi];
        }

        return $resource;
    }
}
