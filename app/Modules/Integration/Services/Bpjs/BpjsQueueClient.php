<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter WS Antrean BPJS (Mobile JKN) yang sesungguhnya.
 *
 * PERINGATAN YANG SAMA seperti adapter VClaim lain: jalur endpoint dan
 * bentuk payload mengikuti dokumentasi yang paling umum beredar, dan WAJIB
 * diverifikasi terhadap sandbox resmi begitu kredensial faskes
 * diterbitkan. Selama kredensial kosong, provider memakai FakeQueueClient.
 *
 * WS Antrean memakai penandatanganan yang sama dengan VClaim, jadi
 * traitnya dipakai bersama.
 */
class BpjsQueueClient implements QueueClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function sendTask(array $payload): array
    {
        return $this->signedRequest('post', '/antrean/updatewaktu', $payload);
    }

    public function cancelQueue(string $bookingCode, string $reason): array
    {
        return $this->signedRequest('post', '/antrean/batal', [
            'kodebooking' => $bookingCode,
            'keterangan' => $reason,
        ]);
    }
}
