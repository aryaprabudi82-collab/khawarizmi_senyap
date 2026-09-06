<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter antrean palsu, deterministik.
 *
 *  - Kode booking diawali '8' -> WS Antrean sedang gangguan.
 *  - Kode booking kosong      -> ditolak (aturan nyata: pembatalan butuh
 *                                kode booking).
 */
class FakeQueueClient implements QueueClient
{
    public function sendTask(array $payload): array
    {
        if (str_starts_with((string) ($payload['kodebooking'] ?? ''), '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'WS Antrean sedang gangguan.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Tahap antrean diterima.',
            'data' => ['taskid' => $payload['taskid'] ?? null],
        ];
    }

    public function cancelQueue(string $bookingCode, string $reason): array
    {
        if ($bookingCode === '') {
            return ['success' => false, 'code' => '400', 'message' => 'Kode booking wajib diisi.', 'data' => []];
        }

        if (str_starts_with($bookingCode, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'WS Antrean sedang gangguan.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'Antrean dibatalkan.', 'data' => []];
    }
}
