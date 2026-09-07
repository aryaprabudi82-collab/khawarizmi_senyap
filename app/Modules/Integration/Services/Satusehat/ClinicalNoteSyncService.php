<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\AssessmentContext;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\Satusehat\Mappers\CarePlanMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\ClinicalImpressionMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\NutritionOrderMapper;
use Carbon\Carbon;
use RuntimeException;

/**
 * Catatan klinis & gizi SATUSEHAT (domain L item O) — 3 kode:
 *
 *   syncClinicalImpression -> satu_sehat_kirim_clinicalimpression
 *   syncCarePlan           -> satu_sehat_kirim_careplan
 *   syncDiet               -> satu_sehat_kirim_diet
 *
 * HANYA ASESMEN YANG SUDAH DIFINALISASI YANG DIKIRIM. Asesmen draf masih
 * bisa berubah; menyebarkan penilaian klinis yang dokternya sendiri belum
 * menyatakan selesai berarti fasilitas lain membaca pendapat setengah jadi
 * tanpa cara membedakannya dari yang final. Penyaringnya ada di
 * AssessmentContext, di satu tempat.
 *
 * SATU ASESMEN MENGHASILKAN DUA RESOURCE YANG BERBEDA SIFATNYA:
 * ClinicalImpression menyatakan APA YANG DINILAI dokter, CarePlan
 * menyatakan APA YANG AKAN DIKERJAKAN. Keduanya boleh ada sendiri-sendiri
 * — asesmen yang menilai tanpa merencanakan tindak lanjut itu wajar, dan
 * sebaliknya juga — jadi yang kosong dilewati, bukan diisi seadanya.
 *
 * TIGA KODE SENGAJA BELUM DIBANGUN, dan alasannya dinyatakan, bukan
 * disamarkan:
 *
 * - satu_sehat_kirim_composition (resume medis): sistem ini belum mencatat
 *   resume medis sebagai dokumen. inpatient.admissions punya
 *   discharge_status, tapi itu KODE cara pulang, bukan ringkasan perawatan
 *   yang ditulis dokter. Composition yang disusun dari kode cara pulang
 *   adalah resume medis kosong yang tampak lengkap.
 *
 * - satu_sehat_kirim_Immunization: tidak ada pencatatan imunisasi di mana
 *   pun. Menyusun Immunization tanpa sumber berarti melaporkan vaksin yang
 *   tidak pernah diberikan — dan itu masuk ke riwayat imunisasi nasional
 *   yang dipakai memutuskan vaksinasi berikutnya.
 *
 * - satu_sehat_tanda_tangan_elektronik: TTE menuntut sertifikat elektronik
 *   dari BSrE berikut kunci privat rumah sakit dan tata cara
 *   penyimpanannya. Itu keputusan kebijakan dan pengadaan, bukan keputusan
 *   teknis, dan tidak boleh "disiapkan" dengan kunci contoh.
 */
class ClinicalNoteSyncService
{
    private const TARGET = 'satusehat';
    private const CONTEXT_KLINIS = 'clinical';
    private const CONTEXT_RANAP = 'inpatient';

    public function __construct(
        private readonly SatusehatClient $client,
        private readonly AssessmentContext $catatan,
        private readonly IdentityMappingService $mappings,
        private readonly OutboundMessageLedger $ledger,
        private readonly ClinicalImpressionMapper $impressions,
        private readonly CarePlanMapper $carePlans,
        private readonly NutritionOrderMapper $diets,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function syncClinicalImpression(int $assessmentId): OutboundMessage
    {
        $asesmen = $this->asesmenFinal($assessmentId);
        [$patientId, $encounterId, $practitionerId] = $this->prasyarat(
            (int) $asesmen->patient_id,
            (int) $asesmen->registration_id,
            $asesmen->practitioner_id
        );

        $resource = $this->impressions->build($asesmen, $patientId, $encounterId, $practitionerId);

        if ($resource === null) {
            throw new RuntimeException(
                "Asesmen #{$assessmentId} tidak memuat penilaian klinis. ClinicalImpression tanpa isi menyatakan dokter sudah menilai dan tidak menemukan apa-apa."
            );
        }

        return $this->kirim('clinicalimpression', 'ClinicalImpression', self::CONTEXT_KLINIS, $assessmentId, $resource, $asesmen->recorded_at);
    }

    /**
     * @throws RuntimeException
     */
    public function syncCarePlan(int $assessmentId): OutboundMessage
    {
        $asesmen = $this->asesmenFinal($assessmentId);
        [$patientId, $encounterId, $practitionerId] = $this->prasyarat(
            (int) $asesmen->patient_id,
            (int) $asesmen->registration_id,
            $asesmen->practitioner_id
        );

        $resource = $this->carePlans->build($asesmen, $patientId, $encounterId, $practitionerId);

        if ($resource === null) {
            throw new RuntimeException(
                "Asesmen #{$assessmentId} tidak memuat rencana tindak lanjut. Rencana tanpa isi bukan rencana."
            );
        }

        return $this->kirim('careplan', 'CarePlan', self::CONTEXT_KLINIS, $assessmentId, $resource, $asesmen->recorded_at);
    }

    /**
     * @throws RuntimeException
     */
    public function syncDiet(int $dietId): OutboundMessage
    {
        $diet = $this->catatan->dietOrder($dietId)
            ?? throw new RuntimeException("Pesanan diet #{$dietId} tidak ditemukan.");

        [$patientId, $encounterId, $practitionerId] = $this->prasyarat(
            (int) $diet->patient_id,
            (int) $diet->registration_id,
            $diet->dpjp_practitioner_id
        );

        $resource = $this->diets->build($diet, $patientId, $encounterId, $practitionerId);

        return $this->kirim('nutritionorder', 'NutritionOrder', self::CONTEXT_RANAP, $dietId, $resource, $diet->start_date);
    }

    /**
     * Mengirim seluruh catatan final satu kunjungan sekaligus.
     *
     * @return array<string, mixed>
     */
    public function syncEncounterNotes(int $registrationId): array
    {
        $ringkasan = ['clinicalimpression' => [], 'careplan' => [], 'dilewati' => []];

        foreach ($this->catatan->finalizedFor($registrationId) as $asesmen) {
            $id = (int) $asesmen->assessment_id;

            try {
                $ringkasan['clinicalimpression'][$id] = $this->syncClinicalImpression($id)->external_reference;
            } catch (RuntimeException $e) {
                $ringkasan['dilewati'][] = "ClinicalImpression #{$id}: " . $e->getMessage();
            }

            try {
                $ringkasan['careplan'][$id] = $this->syncCarePlan($id)->external_reference;
            } catch (RuntimeException $e) {
                $ringkasan['dilewati'][] = "CarePlan #{$id}: " . $e->getMessage();
            }
        }

        return $ringkasan;
    }

    // ---------------------------------------------------------------- privat

    /**
     * @throws RuntimeException
     */
    private function asesmenFinal(int $assessmentId): object
    {
        $final = $this->catatan->finalizedAssessment($assessmentId);

        if ($final !== null) {
            return $final;
        }

        // Dibedakan supaya pesannya berguna: "tidak ada" dan "masih draf"
        // menuntut tindakan yang berbeda dari penggunanya.
        $ada = $this->catatan->assessment($assessmentId);

        throw new RuntimeException($ada === null
            ? "Asesmen #{$assessmentId} tidak ditemukan."
            : "Asesmen #{$assessmentId} masih draf. Penilaian klinis yang belum difinalisasi tidak dikirim ke SATUSEHAT.");
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function kirim(string $resourceKey, string $fhirType, string $context, int $sourceId, array $resource, mixed $eventAt): OutboundMessage
    {
        $existingId = $this->mappings->externalIdFor(self::TARGET, $resourceKey, $context, $sourceId);

        $hasil = $this->client->putResource($fhirType, $existingId, $resource);

        if ($hasil['success']) {
            $this->mappings->remember(self::TARGET, $resourceKey, $context, $sourceId, (string) $hasil['resource_id']);
        }

        return $this->ledger->record(
            targetSystem: self::TARGET,
            resourceType: $resourceKey,
            sourceContext: $context,
            sourceId: $sourceId,
            sourceEventAt: Carbon::parse($eventAt),
            requestPayload: $resource,
            success: $hasil['success'],
            responsePayload: $hasil['response'],
            externalReference: $hasil['resource_id'] ?? null,
            errorMessage: $hasil['success'] ? null : ($hasil['message'] ?? null),
        );
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     *
     * @throws RuntimeException
     */
    private function prasyarat(int $patientId, int $registrationId, mixed $practitionerId): array
    {
        $pasien = $this->mappings->externalIdFor(self::TARGET, 'patient', 'identity', $patientId);
        $kunjungan = $this->mappings->externalIdFor(self::TARGET, 'encounter', 'encounter', $registrationId);

        if ($pasien === null || $kunjungan === null) {
            throw new RuntimeException(
                'Pasien dan kunjungan harus sudah disinkronkan ke SATUSEHAT sebelum mengirim catatan klinis.'
            );
        }

        // Praktisi TIDAK diwajibkan di sini: catatan klinis tetap sah dan
        // berguna meski penulisnya belum dipetakan, dan menahannya berarti
        // menahan isi rekam medis karena alasan administratif.
        $praktisi = $practitionerId === null
            ? null
            : $this->mappings->externalIdFor(self::TARGET, 'practitioner', 'organization', (int) $practitionerId);

        return [$pasien, $kunjungan, $praktisi];
    }
}
