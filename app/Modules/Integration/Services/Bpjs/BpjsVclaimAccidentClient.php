<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter VClaim untuk kecelakaan & Jasa Raharja (domain L item K).
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter VClaim lain: jalur endpoint
 * dan bentuk payload di bawah mengikuti dokumentasi yang paling umum
 * beredar dan WAJIB diverifikasi terhadap sandbox resmi begitu kredensial
 * faskes diterbitkan. Sampai kredensial ada, IntegrationServiceProvider
 * otomatis memakai FakeBpjsAccidentClient.
 */
class BpjsVclaimAccidentClient implements BpjsAccidentClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function registerAccident(array $payload): array
    {
        return $this->signedRequest('post', '/sep/dataindukkecelakaan', ['request' => $payload]);
    }

    public function checkJasaRaharja(string $noKartu, string $tanggalKejadian): array
    {
        return $this->signedRequest('get', "/sep/JasaRaharja/JnsPelayanan/1/noKartu/{$noKartu}/tglPelayanan/{$tanggalKejadian}");
    }

    public function createSupplement(array $payload): array
    {
        return $this->signedRequest('post', '/sep/suplesi/insert', ['request' => $payload]);
    }
}
