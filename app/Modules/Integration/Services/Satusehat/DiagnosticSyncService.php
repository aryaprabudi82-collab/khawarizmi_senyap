<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OrderContext;
use App\Modules\Integration\Services\OutboundMessageLedger;
use App\Modules\Integration\Services\Satusehat\Mappers\DiagnosticObservationMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\DiagnosticReportMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\ServiceRequestMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\SpecimenMapper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Rantai penunjang SATUSEHAT (domain L item H) — 9 kode:
 *
 *   servicerequest_lab / servicerequest_radiologi
 *   specimen_lab       / specimen_radiologi
 *   observation_lab    / observation_radiologi
 *   diagnosticreport_lab / diagnosticreport_radiologi
 *   (patologi anatomi memakai rantai yang sama dengan kategori 'pa')
 *
 * RANTAINYA BERURUTAN, DAN URUTANNYA BUKAN SELERA: tiap resource menunjuk
 * resource sebelumnya dengan ID yang baru diketahui setelah SATUSEHAT
 * menerimanya. ServiceRequest lebih dulu (permintaannya), lalu Specimen
 * (bahan yang diambil untuk permintaan itu), lalu Observation (jawabannya),
 * baru DiagnosticReport (lembar yang merangkumnya). Mengirim
 * DiagnosticReport lebih awal menghasilkan lembar hasil yang menunjuk
 * pemeriksaan yang tidak ada di platform nasional — kosong, tapi tampak
 * lengkap.
 *
 * SATU KEGAGALAN DI TENGAH MENGHENTIKAN SISANYA. Melanjutkan setelah
 * ServiceRequest ditolak berarti mengirim hasil pemeriksaan yang seolah
 * dikerjakan tanpa ada yang meminta. Yang sudah telanjur berhasil tetap
 * tercatat di buku kirim dan ID-nya diingat, sehingga percobaan berikutnya
 * melanjutkan, bukan mengulang dari nol dan menggandakan resource.
 *
 * HANYA HASIL TERVERIFIKASI YANG DIKIRIM — penyaringnya di OrderContext.
 *
 * LAB MIKROBIOLOGI (kode *_labmb) BELUM BERLAKU: kategori pemeriksaan di
 * sistem ini baru lab (patologi klinik), radiologi, dan patologi anatomi.
 * Mikrobiologi bukan sekadar kategori yang belum diisi — hasilnya berbentuk
 * lain (kuman yang tumbuh berikut kepekaan antibiotiknya), dan mengirimkan
 * hasil kultur sebagai Observation biasa akan salah bentuk. Baru bisa
 * dikerjakan setelah pencatatan mikrobiologi ada.
 */
class DiagnosticSyncService
{
    private const TARGET = 'satusehat';
    private const CONTEXT = 'orders';

    public function __construct(
        private readonly SatusehatClient $client,
        private readonly OrderContext $orders,
        private readonly IdentityMappingService $mappings,
        private readonly OutboundMessageLedger $ledger,
        private readonly ServiceRequestMapper $serviceRequests,
        private readonly SpecimenMapper $specimens,
        private readonly DiagnosticObservationMapper $observations,
        private readonly DiagnosticReportMapper $reports,
    ) {}

    /**
     * Mengirim satu permintaan penunjang lengkap dengan hasilnya.
     *
     * @return array<string, mixed> ringkasan tiap tahap, untuk ditampilkan di layar
     *
     * @throws RuntimeException
     */
    public function syncOrder(int $orderId): array
    {
        $butir = $this->orders->verifiedResultsFor($orderId);

        if ($butir->isEmpty()) {
            throw new RuntimeException(
                "Permintaan #{$orderId} belum punya hasil terverifikasi. Hasil yang belum diverifikasi masih bisa berubah."
            );
        }

        $header = $butir->first();
        $jenis = $this->mappingTypeFor($header->category);

        [$patientId, $encounterId, $practitionerId] = $this->prasyarat($header);

        $ringkasan = [
            'order_id' => $orderId,
            'order_number' => $header->order_number,
            'category' => $header->category,
            'servicerequest' => [],
            'specimen' => [],
            'observation' => [],
            'diagnosticreport' => null,
            'belum_dipetakan' => [],
        ];

        // ------------------------------------------------ 1. ServiceRequest
        $serviceRequestIds = [];

        foreach ($butir as $baris) {
            $resource = $this->serviceRequests->build($baris, $patientId, $encounterId, $practitionerId, $jenis);

            if ($resource === null) {
                // Yang belum dipetakan tidak dikirim, dan jumlahnya dilaporkan.
                $ringkasan['belum_dipetakan'][] = $baris->test_code;

                continue;
            }

            $id = $this->kirim('servicerequest', 'ServiceRequest', (int) $baris->result_id, $resource, $header);

            if ($id === null) {
                return $ringkasan + ['gagal_pada' => 'ServiceRequest'];
            }

            $serviceRequestIds[(int) $baris->result_id] = $id;
            $ringkasan['servicerequest'][$baris->test_code] = $id;
        }

        if ($serviceRequestIds === []) {
            // Tidak ada satu pun butir yang kodenya dipetakan: tidak ada yang
            // bisa dikirim, dan itu bukan kegagalan jaringan melainkan
            // pekerjaan pemetaan yang belum selesai.
            return $ringkasan + ['gagal_pada' => 'pemetaan kode'];
        }

        // ----------------------------------------------------- 2. Specimen
        $specimenIds = $this->kirimSpesimen($butir, $patientId, $serviceRequestIds, $header);

        if ($specimenIds === null) {
            return $ringkasan + ['gagal_pada' => 'Specimen'];
        }

        $ringkasan['specimen'] = $specimenIds;

        // -------------------------------------------------- 3. Observation
        $observationIds = [];

        foreach ($butir as $baris) {
            $resultId = (int) $baris->result_id;

            if (! isset($serviceRequestIds[$resultId])) {
                continue;
            }

            $spesimenBaris = $this->specimens->applies($baris->specimen_type)
                ? array_filter([$specimenIds[$baris->specimen_type] ?? null])
                : [];

            $resource = $this->observations->build(
                $baris, $patientId, $encounterId, $jenis,
                $serviceRequestIds[$resultId], $spesimenBaris
            );

            if ($resource === null) {
                continue;
            }

            $id = $this->kirim('observation', 'Observation', $resultId, $resource, $header);

            if ($id === null) {
                return $ringkasan + ['gagal_pada' => 'Observation'];
            }

            $observationIds[] = $id;
            $ringkasan['observation'][$baris->test_code] = $id;
        }

        if ($observationIds === []) {
            return $ringkasan + ['gagal_pada' => 'Observation'];
        }

        // --------------------------------------------- 4. DiagnosticReport
        $resource = $this->reports->build(
            $header, $patientId, $encounterId,
            $observationIds, array_values($specimenIds), array_values($serviceRequestIds)
        );

        $id = $this->kirim('diagnosticreport', 'DiagnosticReport', $orderId, $resource, $header);

        if ($id === null) {
            return $ringkasan + ['gagal_pada' => 'DiagnosticReport'];
        }

        $ringkasan['diagnosticreport'] = $id;

        return $ringkasan + ['gagal_pada' => null];
    }

    /** Permintaan terverifikasi yang belum pernah dikirim lembar hasilnya. */
    public function pending(string $category, int $limit = 50): Collection
    {
        return $this->orders->pendingVerified($category, $limit);
    }

    // ---------------------------------------------------------------- privat

    /**
     * Satu Specimen per JENIS BAHAN, bukan per butir pemeriksaan: sepuluh
     * pemeriksaan darah dari satu kali pengambilan adalah satu tabung, bukan
     * sepuluh. Kuncinya butir pertama yang memakai bahan itu, supaya
     * pengiriman ulang menemukan resource yang sama, bukan membuat yang baru.
     *
     * @param  array<int, string>  $serviceRequestIds
     * @return array<string, string>|null null kalau ada yang gagal dikirim
     */
    private function kirimSpesimen(Collection $butir, string $patientId, array $serviceRequestIds, object $header): ?array
    {
        $perBahan = [];

        foreach ($butir as $baris) {
            if (! $this->specimens->applies($baris->specimen_type)) {
                continue;
            }

            $perBahan[$baris->specimen_type][] = $baris;
        }

        $hasil = [];

        foreach ($perBahan as $bahan => $baris) {
            $pertama = $baris[0];

            $permintaan = array_values(array_filter(array_map(
                fn ($b) => $serviceRequestIds[(int) $b->result_id] ?? null,
                $baris
            )));

            $resource = $this->specimens->build(
                (string) $bahan, $patientId, $permintaan,
                $pertama->entered_at ?? $pertama->resulted_at
            );

            $id = $this->kirim('specimen', 'Specimen', (int) $pertama->result_id, $resource, $header);

            if ($id === null) {
                return null;
            }

            $hasil[$bahan] = $id;
        }

        return $hasil;
    }

    /**
     * Mengirim satu resource, mencatatnya di buku kirim, dan mengingat
     * ID-nya kalau berhasil.
     *
     * @param  array<string, mixed>  $resource
     * @return string|null null kalau gagal
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
            sourceEventAt: Carbon::parse($header->verified_at ?? $header->requested_at),
            requestPayload: $resource,
            success: $hasil['success'],
            responsePayload: $hasil['response'],
            externalReference: $hasil['resource_id'] ?? null,
            errorMessage: $hasil['success'] ? null : ($hasil['message'] ?? null),
        );

        return $hasil['success'] ? (string) $hasil['resource_id'] : null;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws RuntimeException
     */
    private function prasyarat(object $header): array
    {
        $patientId = $this->mappings->externalIdFor(self::TARGET, 'patient', 'identity', (int) $header->patient_id);
        $encounterId = $this->mappings->externalIdFor(self::TARGET, 'encounter', 'encounter', (int) $header->registration_id);

        if ($patientId === null || $encounterId === null) {
            throw new RuntimeException(
                'Pasien dan kunjungan harus sudah disinkronkan ke SATUSEHAT sebelum mengirim hasil penunjang.'
            );
        }

        $practitionerId = $header->requesting_practitioner_id === null
            ? null
            : $this->mappings->externalIdFor(self::TARGET, 'practitioner', 'organization', (int) $header->requesting_practitioner_id);

        if ($practitionerId === null) {
            // Dokter perujuk bukan hiasan pada ServiceRequest: ia yang
            // bertanggung jawab atas permintaannya, dan SATUSEHAT menolak
            // permintaan tanpa requester yang dikenal.
            throw new RuntimeException(
                'Dokter yang meminta pemeriksaan belum dipetakan ke Practitioner SATUSEHAT.'
            );
        }

        return [$patientId, $encounterId, $practitionerId];
    }

    private function mappingTypeFor(string $category): string
    {
        return match ($category) {
            'radiologi' => 'radiologi',
            'pa' => 'pa',
            default => 'lab',
        };
    }
}
