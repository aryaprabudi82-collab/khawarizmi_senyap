<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR MedicationRequest — RESEPNYA
 * (satu_sehat_kirim_medicationrequest).
 *
 * ATURAN PAKAI IKUT DIKIRIM, DAN ITU BUKAN PELENGKAP. Resep yang menyebut
 * obatnya tapi tidak menyebut cara memakainya adalah persis bagian yang
 * paling berbahaya bila hilang: fasilitas lain yang membaca riwayat pasien
 * akan tahu ia mendapat obat tertentu, tanpa tahu sebanyak apa dan
 * seberapa sering. Aturan pakai dikirim apa adanya sebagai teks — bukan
 * diurai jadi frekuensi dan dosis terstruktur, karena penguraian yang
 * salah menghasilkan aturan pakai yang BERBEDA dari yang ditulis dokter,
 * dan kesalahan seperti itu tidak terlihat sebagai kesalahan.
 *
 * YANG DIKIRIM ADALAH JUMLAH YANG DIRESEPKAN, bukan yang diserahkan.
 * Keduanya sering sama, tapi saat berbeda — obat diresepkan 30 dan
 * diserahkan 10 karena stok kurang — yang diminta dokter tetap 30, dan
 * MedicationDispense-lah yang melaporkan 10.
 */
class MedicationRequestMapper
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        stdClass $baris,
        string $patientId,
        string $encounterId,
        string $practitionerId,
        string $medicationId,
    ): array {
        $resource = [
            'resourceType' => 'MedicationRequest',
            'identifier' => [[
                'system' => 'http://sys-ids.kemkes.go.id/prescription',
                'use' => 'official',
                'value' => $baris->prescription_number,
            ]],
            'status' => $this->status($baris),
            'intent' => 'order',
            'medicationReference' => [
                'reference' => "Medication/{$medicationId}",
                'display' => $baris->drug_name,
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'encounter' => ['reference' => "Encounter/{$encounterId}"],
            'authoredOn' => Carbon::parse($baris->prescribed_at)->toIso8601String(),
            'requester' => [
                'reference' => "Practitioner/{$practitionerId}",
                'display' => $baris->prescriber_name,
            ],
            'dispenseRequest' => [
                'quantity' => [
                    'value' => (float) $baris->prescribed_quantity,
                    'unit' => $baris->drug_unit,
                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm',
                ],
            ],
        ];

        // Aturan pakai apa adanya — lihat catatan kelas.
        $resource['dosageInstruction'] = [array_filter([
            'text' => $baris->dosage_instruction ?: null,
            'additionalInstruction' => empty($baris->note) ? null : [['text' => $baris->note]],
        ], fn ($v) => $v !== null)];

        if ($resource['dosageInstruction'] === [[]]) {
            unset($resource['dosageInstruction']);
        }

        // Obat narkotika/psikotropika ditandai: pengawasannya berbeda, dan
        // fasilitas lain berhak tahu tanpa harus mengenali nama obatnya.
        $penanda = array_filter([
            $baris->is_narcotic ? 'Narkotika' : null,
            $baris->is_psychotropic ? 'Psikotropika' : null,
            $baris->is_high_alert ? 'High alert' : null,
        ]);

        if ($penanda !== []) {
            $resource['note'] = [['text' => implode(', ', $penanda)]];
        }

        return $resource;
    }

    /**
     * Status resep menurut FHIR.
     *
     * Resep yang DITOLAK apoteker dilaporkan sebagai 'cancelled', bukan
     * disembunyikan: telaah yang menolak resep adalah kejadian klinis yang
     * berarti, dan menghilangkannya membuat riwayat pasien tampak seolah
     * resep itu tidak pernah ditulis.
     */
    private function status(stdClass $baris): string
    {
        return match ($baris->status) {
            'diserahkan' => 'completed',
            'ditolak', 'batal' => 'cancelled',
            default => 'active',
        };
    }
}
