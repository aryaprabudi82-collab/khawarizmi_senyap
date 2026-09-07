<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\Claim;
use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\PayerReferenceService;
use Illuminate\Support\Collection;

/**
 * Smart Klaim BPJS (domain L item N) — 3 kode:
 *
 *   send  -> bridging_smart_klaim_bpjs
 *   (mapping_penyakit_smart_klaim_bpjs dan mapping_prosedur_smart_klaim_bpjs
 *    memakai mekanisme pemetaan kode penjamin dari item E — dua jenis
 *    pemetaan baru, bukan dua tabel baru)
 *
 * SMART KLAIM BUKAN KLAIM KEDUA. Ia cara lain MENGIRIMKAN klaim yang sama:
 * isinya identik dengan klaim INA-CBG yang sudah dibekukan, hanya
 * bentuknya FHIR Bundle alih-alih berkas grouper. Karena itu ia disusun
 * dari isi klaim yang SUDAH DIBEKUKAN — bukan dari rekam medis yang
 * dibaca ulang. Membaca ulang berarti klaim yang sudah diverifikasi BPJS
 * bisa berubah tanpa ada yang menyentuhnya, persis kesalahan yang
 * dihindari item C.
 *
 * KODE ICD DILEWATKAN APA ADANYA, DAN ITU BUKAN KELALAIAN. Berbeda dari
 * SATUSEHAT yang menuntut SNOMED/LOINC/KFA — sistem kode yang sama sekali
 * lain dari milik kita, sehingga yang belum dipetakan tidak boleh dikirim —
 * Smart Klaim memakai ICD-10 dan ICD-9-CM, persis yang sudah kita simpan.
 * Mewajibkan pemetaan di sini akan menahan setiap klaim tanpa menambah
 * satu pun kebenaran. Pemetaan tetap disediakan untuk PENGECUALIAN: kalau
 * BPJS meminta kode yang berbeda untuk kasus tertentu, pemetaan itulah
 * yang dipakai, dan hanya itu.
 */
class SmartClaimService
{
    /** Jenis pemetaan pada mekanisme kode penjamin item E. */
    public const PEMETAAN_PENYAKIT = 'penyakit-smart-klaim';
    public const PEMETAAN_PROSEDUR = 'prosedur-smart-klaim';

    private const TARGET = 'bpjs';
    private const RESOURCE = 'smart-klaim';
    private const CONTEXT = 'integration';

    /** Status klaim yang isinya sudah dibekukan dan boleh dikirim ulang lewat FHIR. */
    private const SIAP_KIRIM = [Claim::TERKIRIM, Claim::TERVERIFIKASI];

    public function __construct(
        private readonly BpjsSmartClaimClient $client,
        private readonly PayerReferenceService $pemetaan,
        private readonly OutboundMessageLedger $ledger,
    ) {}

    /**
     * Mengirim satu klaim sebagai FHIR Bundle.
     *
     * @throws IntegrationException
     */
    public function send(Claim $claim, ?int $actorId = null): OutboundMessage
    {
        if (! in_array($claim->status, self::SIAP_KIRIM, true)) {
            throw new IntegrationException(
                "Smart Klaim menyusun ulang klaim yang isinya sudah dibekukan; klaim berstatus '{$claim->status}' belum punya isi beku."
            );
        }

        if (empty($claim->diagnoses)) {
            throw new IntegrationException('Klaim tanpa diagnosis akan ditolak Smart Klaim.');
        }

        $bundle = $this->buildBundle($claim);

        $hasil = $this->client->sendBundle($bundle);

        return $this->ledger->record(
            targetSystem: self::TARGET,
            resourceType: self::RESOURCE,
            sourceContext: self::CONTEXT,
            sourceId: $claim->id,
            // Peristiwa sumbernya adalah saat klaim DIKIRIM ke grouper —
            // itulah saat isinya dibekukan, dan itu yang membuat pengiriman
            // ulang atas isi yang sama tidak jadi baris baru di buku kirim.
            sourceEventAt: $claim->submitted_at ?? $claim->created_at,
            requestPayload: $bundle,
            success: (bool) ($hasil['success'] ?? false),
            responsePayload: $hasil,
            externalReference: $hasil['data']['bundleId'] ?? null,
            errorMessage: ($hasil['success'] ?? false) ? null : ($hasil['message'] ?? null),
        );
    }

    /**
     * Bundle FHIR dari isi klaim yang sudah dibekukan.
     *
     * @return array<string, mixed>
     */
    public function buildBundle(Claim $claim): array
    {
        $diagnosis = [];

        foreach ($claim->diagnoses as $urut => $baris) {
            $kode = (string) ($baris['code'] ?? '');

            if ($kode === '') {
                continue;
            }

            $diagnosis[] = [
                'sequence' => $urut + 1,
                'diagnosisCodeableConcept' => [
                    'coding' => [[
                        'system' => 'http://hl7.org/fhir/sid/icd-10',
                        'code' => $this->kodePenyakit($kode),
                        'display' => $baris['display'] ?? null,
                    ]],
                ],
                // Diagnosis utama ditandai: tarif CBG bersandar padanya, dan
                // bundle tanpa penanda membuat BPJS memilih sendiri yang mana.
                'type' => [[
                    'coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/ex-diagnosistype',
                        'code' => ($baris['rank'] ?? null) === 'utama' ? 'principal' : 'secondary',
                    ]],
                ]],
            ];
        }

        $prosedur = [];

        foreach ($claim->procedures ?? [] as $urut => $baris) {
            $kode = (string) ($baris['code'] ?? '');

            if ($kode === '') {
                continue;
            }

            $prosedur[] = [
                'sequence' => $urut + 1,
                'procedureCodeableConcept' => [
                    'coding' => [[
                        'system' => 'http://hl7.org/fhir/sid/icd-9-cm',
                        'code' => $this->kodeProsedur($kode),
                        'display' => $baris['display'] ?? null,
                    ]],
                ],
            ];
        }

        $klaim = [
            'resourceType' => 'Claim',
            'identifier' => [[
                'system' => 'http://sys-ids.kemkes.go.id/claim',
                'value' => $claim->claim_number,
            ]],
            'status' => 'active',
            'type' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/claim-type',
                    'code' => $claim->care_type === 'ranap' ? 'institutional' : 'professional',
                ]],
            ],
            'use' => 'claim',
            'patient' => ['display' => $claim->patient_name],
            'created' => ($claim->submitted_at ?? $claim->created_at)->toIso8601String(),
            'insurance' => [[
                'sequence' => 1,
                'focal' => true,
                'identifier' => ['value' => $claim->card_number],
            ]],
            'supportingInfo' => array_values(array_filter([
                $claim->sep_number === null ? null : [
                    'sequence' => 1,
                    'category' => ['text' => 'No. SEP'],
                    'valueString' => $claim->sep_number,
                ],
                // Kode CBG ikut dikirim sebagaimana DITERIMA dari grouper —
                // tidak pernah dihitung sendiri di sini (aturan item C).
                $claim->cbg_code === null ? null : [
                    'sequence' => 2,
                    'category' => ['text' => 'Kode CBG dari grouper'],
                    'valueString' => $claim->cbg_code,
                ],
            ])),
            'diagnosis' => $diagnosis,
            'total' => [
                'value' => (float) $claim->hospital_charge,
                'currency' => 'IDR',
            ],
        ];

        if ($prosedur !== []) {
            $klaim['procedure'] = $prosedur;
        }

        return [
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => [[
                'resource' => $klaim,
                'request' => ['method' => 'POST', 'url' => 'Claim'],
            ]],
        ];
    }

    /** Riwayat pengiriman Smart Klaim satu klaim. */
    public function transmissions(Claim $claim): Collection
    {
        return OutboundMessage::query()
            ->where('target_system', self::TARGET)
            ->where('resource_type', self::RESOURCE)
            ->where('source_id', $claim->id)
            ->orderByDesc('id')
            ->get();
    }

    // ---------------------------------------------------------------- privat

    /**
     * Kode penyakit untuk Smart Klaim.
     *
     * Pemetaan hanya dipakai bila BPJS memang meminta kode berbeda; kalau
     * tidak ada, ICD-10 kita dikirim apa adanya — lihat catatan kelas.
     */
    private function kodePenyakit(string $kode): string
    {
        return $this->pemetaan->resolve('bpjs', self::PEMETAAN_PENYAKIT, $kode) ?? $kode;
    }

    private function kodeProsedur(string $kode): string
    {
        return $this->pemetaan->resolve('bpjs', self::PEMETAAN_PROSEDUR, $kode) ?? $kode;
    }
}
