<?php

namespace App\Modules\Integration\Services\Satusehat\Mappers;

use Carbon\Carbon;
use stdClass;

/**
 * Menyusun resource FHIR MedicationDispense — PENYERAHAN obatnya
 * (satu_sehat_kirim_medicationdispense).
 *
 * YANG DIKIRIM ADALAH JUMLAH YANG BENAR-BENAR DISERAHKAN, bukan yang
 * diresepkan. Kalau obat diresepkan 30 dan hanya 10 yang tersedia, yang
 * diterima pasien memang 10 — dan fasilitas berikutnya perlu tahu itu
 * untuk memperkirakan kapan obatnya habis. Melaporkan 30 membuat mereka
 * mengira pasien punya persediaan tiga kali lipat dari kenyataannya.
 *
 * SUBSTITUSI DILAPORKAN SEBAGAI SUBSTITUSI. Obat yang diganti apoteker
 * punya tempatnya sendiri di FHIR, dan mengirimkannya seolah obat itulah
 * yang diresepkan dokter menghapus jejak keputusan yang sebenarnya diambil
 * orang lain.
 *
 * PENYERAHAN YANG BELUM TERJADI TIDAK DISUSUN SAMA SEKALI: resource ini
 * menyatakan obat sudah berpindah ke tangan pasien, dan menyatakannya
 * lebih awal berarti melaporkan sesuatu yang belum terjadi.
 */
class MedicationDispenseMapper
{
    /**
     * @return array<string, mixed>|null null kalau obatnya belum diserahkan
     */
    public function build(
        stdClass $baris,
        string $patientId,
        string $encounterId,
        string $medicationId,
        ?string $medicationRequestId = null,
    ): ?array {
        if (empty($baris->dispensed_at) || ($baris->dispensed_quantity ?? 0) <= 0) {
            return null;
        }

        $resource = [
            'resourceType' => 'MedicationDispense',
            'identifier' => [[
                'system' => 'http://sys-ids.kemkes.go.id/prescription-dispense',
                'use' => 'official',
                'value' => $baris->prescription_number . '-' . $baris->item_id,
            ]],
            'status' => 'completed',
            'medicationReference' => [
                'reference' => "Medication/{$medicationId}",
                'display' => $baris->drug_name,
            ],
            'subject' => ['reference' => "Patient/{$patientId}"],
            'context' => ['reference' => "Encounter/{$encounterId}"],
            'quantity' => [
                'value' => (float) $baris->dispensed_quantity,
                'unit' => $baris->drug_unit,
            ],
            'whenHandedOver' => Carbon::parse($baris->dispensed_at)->toIso8601String(),
        ];

        if ($medicationRequestId !== null) {
            $resource['authorizingPrescription'] = [
                ['reference' => "MedicationRequest/{$medicationRequestId}"],
            ];
        }

        if (! empty($baris->dispensed_by_name)) {
            $resource['performer'] = [[
                'actor' => ['display' => $baris->dispensed_by_name],
            ]];
        }

        if (! empty($baris->dosage_instruction)) {
            $resource['dosageInstruction'] = [['text' => $baris->dosage_instruction]];
        }

        // Substitusi punya tempatnya sendiri — lihat catatan kelas.
        if (! empty($baris->substituted_from_drug_id)) {
            $resource['substitution'] = [
                'wasSubstituted' => true,
                'reason' => [[
                    'text' => 'Diganti apoteker dari ' . ($baris->substituted_from_drug_name ?: 'obat lain'),
                ]],
            ];
        }

        return $resource;
    }
}
