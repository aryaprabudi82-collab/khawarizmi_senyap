<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR CarePlan dari bagian "P" (Plan) catatan SOAP
 * (satu_sehat_kirim_careplan).
 *
 * CarePlan adalah RENCANA tindak lanjut: apa yang akan dikerjakan, bukan
 * apa yang sudah. Karena itu statusnya mengikuti keadaan asesmennya —
 * rencana dari kunjungan yang sudah selesai dilaporkan 'completed', bukan
 * selamanya 'active'. Rencana yang selamanya aktif membuat fasilitas lain
 * mengira pasien masih menjalani program yang sebetulnya sudah tuntas.
 *
 * KOSONG BERARTI TIDAK DISUSUN, aturan yang sama seperti
 * ClinicalImpression: rencana tanpa isi bukan rencana.
 */
class CarePlanMapper
{
    /**
     * @return array<string, mixed>|null null kalau rencananya kosong
     */
    public function build(stdClass $asesmen, string $patientId, string $encounterId, ?string $practitionerId = null): ?array
    {
        $rencana = trim((string) ($asesmen->plan ?? ''));

        if ($rencana === '') {
            return null;
        }

        $resource = [
            'resourceType' => 'CarePlan',
            'status' => $asesmen->finalized_at !== null ? 'completed' : 'active',
            'intent' => 'plan',
            'title' => 'Rencana asuhan ' . ($asesmen->unit_name ?? 'rawat jalan'),
            'description' => $rencana,
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'created' => Carbon::parse($asesmen->finalized_at ?? $asesmen->recorded_at)->toIso8601String(),
            'period' => ['start' => Carbon::parse($asesmen->recorded_at)->toIso8601String()],
            'activity' => [[
                'detail' => [
                    'status' => 'not-started',
                    'description' => $rencana,
                ],
            ]],
        ];

        if ($practitionerId !== null) {
            $resource['author'] = [
                'reference' => "Practitioner/{$practitionerId}",
                'display' => $asesmen->practitioner_name,
            ];
        }

        return $resource;
    }
}
