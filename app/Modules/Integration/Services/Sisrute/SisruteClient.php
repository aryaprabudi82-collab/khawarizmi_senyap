<?php

namespace App\Modules\Integration\Services\Sisrute;

/**
 * Kontrak adapter Sisrute Kemenkes (domain L item P).
 */
interface SisruteClient
{
    /**
     * Mengajukan rujukan keluar (sisrute_rujukan_keluar).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function sendReferral(array $payload): array;

    /**
     * Menarik rujukan yang sudah diajukan.
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function cancelReferral(string $sisruteNumber, string $reason): array;

    /**
     * Mengambil rujukan masuk yang ditujukan ke faskes kita
     * (sisrute_rujukan_masuk).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function fetchIncoming(string $from, string $until): array;

    /**
     * Menjawab rujukan masuk: sanggup atau tidak.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function respondIncoming(array $payload): array;

    /**
     * Daftar referensi Sisrute (alasan rujuk, diagnosa, faskes).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function references(string $type): array;
}
