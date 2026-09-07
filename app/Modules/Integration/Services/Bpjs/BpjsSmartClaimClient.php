<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter Smart Klaim BPJS (domain L item N).
 */
interface BpjsSmartClaimClient
{
    /**
     * Mengirim bundle FHIR klaim (bridging_smart_klaim_bpjs).
     *
     * @param  array<string, mixed>  $bundle
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function sendBundle(array $bundle): array;
}
