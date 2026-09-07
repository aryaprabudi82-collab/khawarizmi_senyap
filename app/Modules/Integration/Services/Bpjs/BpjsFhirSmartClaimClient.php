<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter Smart Klaim BPJS (domain L item N).
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter BPJS lain: jalur endpoint
 * dan bentuk bundle mengikuti dokumentasi yang paling umum beredar dan
 * WAJIB diverifikasi terhadap sandbox resmi begitu kredensial diterbitkan.
 * Sampai kredensial ada, IntegrationServiceProvider memakai adapter palsu.
 */
class BpjsFhirSmartClaimClient implements BpjsSmartClaimClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function sendBundle(array $bundle): array
    {
        return $this->signedRequest('post', '/smartclaim/Bundle', $bundle);
    }
}
