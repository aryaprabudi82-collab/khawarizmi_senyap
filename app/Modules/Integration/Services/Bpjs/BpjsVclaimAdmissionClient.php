<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter VClaim untuk Surat PRI & reklasifikasi SEP (domain L item L).
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter VClaim lain: jalur endpoint
 * dan bentuk payload di bawah mengikuti dokumentasi yang paling umum
 * beredar dan WAJIB diverifikasi terhadap sandbox resmi begitu kredensial
 * faskes diterbitkan. Sampai kredensial ada, IntegrationServiceProvider
 * otomatis memakai FakeBpjsAdmissionClient.
 */
class BpjsVclaimAdmissionClient implements BpjsAdmissionClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function createAdmissionOrder(array $payload): array
    {
        return $this->signedRequest('post', '/RencanaKontrol/InsertSPRI', ['request' => $payload]);
    }

    public function cancelAdmissionOrder(string $orderNumber, string $reason): array
    {
        return $this->signedRequest('delete', '/RencanaKontrol/Delete', [
            'request' => ['t_suratkontrol' => ['noSuratKontrol' => $orderNumber, 'user' => $reason]],
        ]);
    }

    /**
     * VClaim tidak punya endpoint bernama "reklasifikasi": perubahan jenis
     * pelayanan dan kelas rawat dilakukan lewat PEMBARUAN SEP. Pemetaan itu
     * dinyatakan di sini alih-alih disamarkan, karena bentuk payload
     * pembaruan SEP berbeda antar versi dokumentasi dan justru bagian ini
     * yang paling perlu diadu dengan sandbox resmi lebih dulu.
     */
    public function reclassifySep(array $payload): array
    {
        return $this->signedRequest('put', '/SEP/2.0/update', ['request' => ['t_sep' => $payload]]);
    }
}
