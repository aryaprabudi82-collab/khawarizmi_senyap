<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter Aplicares & iCare BPJS yang sesungguhnya.
 *
 * PERINGATAN YANG SAMA seperti adapter VClaim lain: jalur endpoint dan
 * bentuk payload mengikuti dokumentasi yang paling umum beredar, dan WAJIB
 * diverifikasi terhadap sandbox resmi begitu kredensial faskes diterbitkan.
 * Selama kredensial kosong, provider otomatis memakai FakeAplicaresClient.
 *
 * Penandatanganannya dipakai bersama lewat trait, bukan disalin.
 */
class BpjsAplicaresClient implements AplicaresClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
        private readonly string $ppkCode,
    ) {}

    public function reportBedAvailability(array $rooms): array
    {
        return $this->signedRequest('post', '/aplicares/rest/bed/update/' . $this->ppkCode, [
            'kamar' => $rooms,
        ]);
    }

    public function memberCareHistory(string $cardNumber): array
    {
        return $this->signedRequest('get', "/iCare/RS/{$cardNumber}/{$this->ppkCode}");
    }
}
