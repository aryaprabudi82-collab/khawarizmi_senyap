<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\PharmacyContext;
use App\Modules\Integration\Services\Satusehat\Mappers\MedicationDispenseMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\MedicationMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\MedicationRequestMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\PharmacyReviewMapper;
use Carbon\Carbon;
use RuntimeException;

/**
 * Rantai farmasi SATUSEHAT (domain L item I) — 5 kode:
 *
 *   satu_sehat_kirim_medication
 *   satu_sehat_kirim_medicationrequest
 *   satu_sehat_kirim_medicationdispense
 *   satu_sehat_kirim_questionresponse_telaah_farmasi
 *   (satu_sehat_kirim_medicationstatement: lihat catatan di bawah)
 *
 * URUTANNYA SAMA PENTINGNYA seperti rantai penunjang: Medication dulu
 * (obatnya sendiri), lalu MedicationRequest (resepnya), baru
 * MedicationDispense (penyerahannya) yang menunjuk keduanya. Telaah
 * apoteker dikirim terpisah karena ia menjawab resep secara keseluruhan,
 * bukan per baris obat.
 *
 * SATU MEDICATION PER OBAT, BUKAN PER BARIS RESEP. Obat yang sama
 * diresepkan pada sepuluh pasien tetap satu obat; menyusun sepuluh
 * Medication membuat platform nasional mengira ada sepuluh obat berbeda
 * dengan kode KFA yang sama.
 *
 * MEDICATIONSTATEMENT SENGAJA BELUM DIBUAT. Resource itu menyatakan obat
 * yang SEDANG DIPAKAI pasien menurut pengakuannya sendiri — obat dari
 * rumah, dari dokter lain, yang dibeli bebas. Sistem ini tidak mencatatnya
 * di mana pun: pharmacy.external_prescriptions memang ada, tapi itu resep
 * luar yang DILAYANI apotek kita untuk pembeli umum, tanpa nomor rekam
 * medis, sehingga tidak bisa dinyatakan sebagai obat pasien tertentu.
 * Menyusun MedicationStatement dari data yang tidak ada berarti mengarang
 * daftar obat yang sedang diminum pasien — dan daftar itulah yang dibaca
 * fasilitas lain saat memeriksa interaksi obat. Baru bisa dikerjakan
 * setelah pencatatan riwayat obat pasien ada.
 */
class MedicationSyncService
{
    private const TARGET = 'satusehat';
    private const CONTEXT = 'pharmacy';

    public function __construct(
        private readonly SatusehatClient $client,
        private readonly PharmacyContext $pharmacy,
        private readonly CodeMappingService $codes,
        private readonly IdentityMappingService $mappings,
        private readonly OutboundMessageLedger $ledger,
        private readonly MedicationMapper $medications,
        private readonly MedicationRequestMapper $requests,
        private readonly MedicationDispenseMapper $dispenses,
        private readonly PharmacyReviewMapper $reviews,
    ) {}

    /**
     * Mengirim satu resep lengkap: obat, permintaan, dan penyerahannya.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function syncPrescription(int $prescriptionId): array
    {
        $baris = $this->pharmacy->itemsFor($prescriptionId);

        if ($baris->isEmpty()) {
            throw new RuntimeException("Resep #{$prescriptionId} tidak punya baris obat.");
        }

        $header = $baris->first();

        [$patientId, $encounterId, $practitionerId] = $this->prasyarat($header);

        $ringkasan = [
            'prescription_id' => $prescriptionId,
            'prescription_number' => $header->prescription_number,
            'medication' => [],
            'medicationrequest' => [],
            'medicationdispense' => [],
            'belum_dipetakan' => [],
            'belum_diserahkan' => [],
        ];

        // ---------------------------------------------------- 1. Medication
        $medicationIds = [];

        foreach ($this->pharmacy->drugsIn($prescriptionId) as $obat) {
            $resource = $this->medications->build($obat, $this->client->organizationId());

            if ($resource === null) {
                // Kode KFA belum dipetakan: obatnya tidak dikirim, dan
                // disebutkan. Menebak kode KFA berarti melaporkan obat lain
                // dengan dosis lain — kesalahan yang bisa mencelakakan orang.
                $ringkasan['belum_dipetakan'][] = $obat->drug_code;

                continue;
            }

            $id = $this->kirim('medication', 'Medication', (int) $obat->drug_id, $resource, $header);

            if ($id === null) {
                return $ringkasan + ['gagal_pada' => 'Medication'];
            }

            $medicationIds[(int) $obat->drug_id] = $id;
            $ringkasan['medication'][$obat->drug_code] = $id;
        }

        if ($medicationIds === []) {
            return $ringkasan + ['gagal_pada' => 'pemetaan kode'];
        }

        // --------------------------------------------- 2. MedicationRequest
        $requestIds = [];

        foreach ($baris as $item) {
            $drugId = (int) $item->drug_id;

            if (! isset($medicationIds[$drugId])) {
                continue;
            }

            $resource = $this->requests->build(
                $item, $patientId, $encounterId, $practitionerId, $medicationIds[$drugId]
            );

            $id = $this->kirim('medicationrequest', 'MedicationRequest', (int) $item->item_id, $resource, $header);

            if ($id === null) {
                return $ringkasan + ['gagal_pada' => 'MedicationRequest'];
            }

            $requestIds[(int) $item->item_id] = $id;
            $ringkasan['medicationrequest'][$item->drug_name] = $id;
        }

        // -------------------------------------------- 3. MedicationDispense
        foreach ($baris as $item) {
            $itemId = (int) $item->item_id;
            $drugId = (int) $item->drug_id;

            if (! isset($requestIds[$itemId])) {
                continue;
            }

            $resource = $this->dispenses->build(
                $item, $patientId, $encounterId, $medicationIds[$drugId], $requestIds[$itemId]
            );

            if ($resource === null) {
                // Belum diserahkan: bukan kegagalan, melainkan keadaan yang
                // memang belum terjadi. Menyusunnya lebih awal berarti
                // melaporkan obat sudah berpindah ke tangan pasien.
                $ringkasan['belum_diserahkan'][] = $item->drug_name;

                continue;
            }

            $id = $this->kirim('medicationdispense', 'MedicationDispense', $itemId, $resource, $header);

            if ($id === null) {
                return $ringkasan + ['gagal_pada' => 'MedicationDispense'];
            }

            $ringkasan['medicationdispense'][$item->drug_name] = $id;
        }

        return $ringkasan + ['gagal_pada' => null];
    }

    /**
     * Mengirim telaah apoteker atas satu resep.
     *
     * @throws RuntimeException
     */
    public function syncReview(int $prescriptionId): string
    {
        $telaah = $this->pharmacy->reviewFor($prescriptionId);

        if ($telaah === null) {
            throw new RuntimeException(
                "Resep #{$prescriptionId} belum ditelaah apoteker. Telaah yang belum dikerjakan tidak sama dengan telaah tanpa temuan."
            );
        }

        $header = $this->pharmacy->header($prescriptionId);

        // Telaah tidak menyebut dokter mana pun — ia pernyataan apoteker atas
        // resepnya. Jadi pemetaan dokter TIDAK disyaratkan di sini, berbeda
        // dari MedicationRequest yang memang perlu penanggung jawabnya.
        [$patientId, $encounterId] = $this->pasienDanKunjungan($header);

        $resource = $this->reviews->build($telaah, $patientId, $encounterId);

        $id = $this->kirim('pharmacyreview', 'QuestionnaireResponse', (int) $telaah->review_id, $resource, $header);

        if ($id === null) {
            throw new RuntimeException('Telaah farmasi gagal dikirim ke SATUSEHAT.');
        }

        return $id;
    }

    /**
     * Menyiapkan baris pemetaan obat dari master, berikut kode KFA yang
     * sudah terisi di sana.
     *
     * Kolom kfa_code di master obat BUKAN sumber kode saat mengirim — itu
     * akan jadi sumber kedua yang bisa berbeda dari pemetaan. Di sini ia
     * dipakai sebagaimana mestinya: sebagai bahan awal, supaya pemetaan
     * tidak perlu diisi ulang dari nol untuk obat yang kodenya sudah
     * diketahui.
     *
     * @return array{disiapkan: int, terisi_dari_master: int}
     */
    public function seedMappingsFromDrugMaster(?int $actorId = null): array
    {
        $obat = $this->pharmacy->drugMaster();

        $disiapkan = $this->codes->seedFrom('obat', $obat->map(fn ($o) => [
            'code' => $o->drug_code,
            'name' => $o->drug_name,
        ]));

        $terisi = 0;

        foreach ($obat as $o) {
            if (empty($o->kfa_code) || $this->codes->resolve('obat', $o->drug_code) !== null) {
                continue;
            }

            $this->codes->map('obat', $o->drug_code, 'kfa', $o->kfa_code, $o->drug_name, $actorId);
            $terisi++;
        }

        return ['disiapkan' => $disiapkan, 'terisi_dari_master' => $terisi];
    }

    // ---------------------------------------------------------------- privat

    /**
     * @param  array<string, mixed>  $resource
     */
    private function kirim(string $resourceKey, string $fhirType, int $sourceId, array $resource, object $header): ?string
    {
        $existingId = $this->mappings->externalIdFor(self::TARGET, $resourceKey, self::CONTEXT, $sourceId);

        $hasil = $this->client->putResource($fhirType, $existingId, $resource);

        if ($hasil['success']) {
            $this->mappings->remember(self::TARGET, $resourceKey, self::CONTEXT, $sourceId, (string) $hasil['resource_id']);
        }

        $this->ledger->record(
            targetSystem: self::TARGET,
            resourceType: $resourceKey,
            sourceContext: self::CONTEXT,
            sourceId: $sourceId,
            sourceEventAt: Carbon::parse($header->prescribed_at),
            requestPayload: $resource,
            success: $hasil['success'],
            responsePayload: $hasil['response'],
            externalReference: $hasil['resource_id'] ?? null,
            errorMessage: $hasil['success'] ? null : ($hasil['message'] ?? null),
        );

        return $hasil['success'] ? (string) $hasil['resource_id'] : null;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws RuntimeException
     */
    private function pasienDanKunjungan(object $header): array
    {
        $patientId = $this->mappings->externalIdFor(self::TARGET, 'patient', 'identity', (int) $header->patient_id);
        $encounterId = $this->mappings->externalIdFor(self::TARGET, 'encounter', 'encounter', (int) $header->registration_id);

        if ($patientId === null || $encounterId === null) {
            throw new RuntimeException(
                'Pasien dan kunjungan harus sudah disinkronkan ke SATUSEHAT sebelum mengirim data farmasi.'
            );
        }

        return [$patientId, $encounterId];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws RuntimeException
     */
    private function prasyarat(object $header): array
    {
        [$patientId, $encounterId] = $this->pasienDanKunjungan($header);

        $practitionerId = $header->prescriber_id === null
            ? null
            : $this->mappings->externalIdFor(self::TARGET, 'practitioner', 'organization', (int) $header->prescriber_id);

        if ($practitionerId === null) {
            // Dokter penulis resep bukan pelengkap: MedicationRequest tanpa
            // requester adalah resep tanpa yang bertanggung jawab atasnya.
            throw new RuntimeException(
                'Dokter penulis resep belum dipetakan ke Practitioner SATUSEHAT.'
            );
        }

        return [$patientId, $encounterId, $practitionerId];
    }
}
