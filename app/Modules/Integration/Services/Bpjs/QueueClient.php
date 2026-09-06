<?php

namespace App\Modules\Integration\Services\Bpjs;

/** Kontrak adapter WS Antrean BPJS (Mobile JKN). */
interface QueueClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function sendTask(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function cancelQueue(string $bookingCode, string $reason): array;
}
