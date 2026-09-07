<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR ClinicalImpression dari bagian "A" (Assessment)
 * catatan SOAP (satu_sehat_kirim_clinicalimpression).
 *
 * ClinicalImpression adalah PENILAIAN dokter — kesimpulan yang ia tarik
 * dari keluhan dan pemeriksaan, sebelum menjadi diagnosis berkode. Ia
 * bernilai justru karena naratif: yang tidak muat dalam kode ICD-10 ada
 * di sini.
 *
 * KOSONG BERARTI TIDAK DISUSUN. ClinicalImpression tanpa isi adalah
 * penilaian klinis yang tidak menyatakan apa pun; mengirimkannya membuat
 * fasilitas lain mengira dokter sudah menilai dan tidak menemukan apa-apa,
 * padahal bagian itu memang tidak diisi.
 *
 * BAGIAN "S" DAN "O" IKUT SEBAGAI PENUNJANG, bukan sebagai isi utama:
 * keluhan dan temuan pemeriksaanlah yang membuat penilaian bisa
 * ditafsirkan, tapi yang dinyatakan resource ini tetap penilaiannya.
 */
class ClinicalImpressionMapper
{
    /**
     * @return array<string, mixed>|null null kalau penilaiannya kosong
     */
    public function build(stdClass $asesmen, string $patientId, string $encounterId, ?string $practitionerId = null): ?array
    {
        $penilaian = trim((string) ($asesmen->assessment ?? ''));

        if ($penilaian === '') {
            return null;
        }

        $resource = [
            'resourceType' => 'ClinicalImpression',
            'status' => 'completed',
            'description' => $penilaian,
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'effectiveDateTime' => Carbon::parse($asesmen->recorded_at)->toIso8601String(),
            'date' => Carbon::parse($asesmen->finalized_at ?? $asesmen->recorded_at)->toIso8601String(),
            'summary' => $penilaian,
        ];

        if ($practitionerId !== null) {
            $resource['assessor'] = [
                'reference' => "Practitioner/{$practitionerId}",
                'display' => $asesmen->practitioner_name,
            ];
        }

        $penunjang = array_filter([
            'Keluhan utama' => $asesmen->chief_complaint ?? null,
            'Subjektif' => $asesmen->subjective ?? null,
            'Objektif' => $asesmen->objective ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        if ($penunjang !== []) {
            $resource['note'] = array_map(
                fn ($label, $isi) => ['text' => "{$label}: {$isi}"],
                array_keys($penunjang),
                array_values($penunjang)
            );
        }

        return $resource;
    }
}
