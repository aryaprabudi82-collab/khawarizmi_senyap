<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter Apotek Online (ApOL) BPJS — domain L item M.
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter BPJS lain: jalur endpoint
 * dan bentuk payload di bawah mengikuti dokumentasi yang paling umum
 * beredar dan WAJIB diverifikasi terhadap sandbox resmi begitu kredensial
 * apotek diterbitkan. Sampai kredensial ada, IntegrationServiceProvider
 * otomatis memakai FakeBpjsApotekClient.
 *
 * ApOL memakai skema tanda tangan yang sama dengan VClaim, jadi trait yang
 * sama dipakai — bukan disalin.
 */
class BpjsApolApotekClient implements BpjsApotekClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function sendPrescription(array $payload): array
    {
        return $this->signedRequest('post', '/apotek/resep/insert', ['request' => $payload]);
    }

    public function findSep(string $sepNumber): array
    {
        return $this->signedRequest('get', "/apotek/sep/{$sepNumber}");
    }

    public function requestIteration(array $payload): array
    {
        return $this->signedRequest('post', '/apotek/pelayanan/iterasi/insert', ['request' => $payload]);
    }
}
