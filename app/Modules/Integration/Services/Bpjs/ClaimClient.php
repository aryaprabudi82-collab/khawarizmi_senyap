<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter grouper INA-CBG & monitoring klaim (domain L item C).
 *
 * group() MENGEMBALIKAN kode CBG dan tarifnya; tidak pernah menghitungnya
 * di sisi kita. Grouper adalah aplikasi terpisah milik Kemenkes.
 */
interface ClaimClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function group(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function monitorClaims(string $scope, string $from, string $until): array;
}
