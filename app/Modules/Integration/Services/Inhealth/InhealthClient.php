<?php

namespace App\Modules\Integration\Services\Inhealth;

/**
 * Kontrak adapter Mandiri Inhealth (domain L item Q).
 */
interface InhealthClient
{
    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function checkEligibility(string $memberNumber, string $serviceDate): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function createGuarantee(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function cancelGuarantee(string $sjpNumber, string $reason): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function submitBilling(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function references(string $type): array;
}
