<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter VClaim BPJS. Dua hal saja yang dibutuhkan Wave 1: cek
 * eligibilitas peserta dan siklus SEP (terbit/batal). Setiap panggilan
 * mengembalikan bentuk yang sama supaya pemanggil (EligibilityService,
 * SepService) tidak perlu tahu apakah jawabannya dari API asli atau palsu.
 */
interface BpjsClient
{
    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function checkEligibility(string $noKartu, string $tanggalPelayanan): array;

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function createSep(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function cancelSep(string $sepNumber, string $reason): array;
}
