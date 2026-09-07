<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter VClaim untuk Surat Perintah Rawat Inap & reklasifikasi
 * SEP (domain L item L).
 */
interface BpjsAdmissionClient
{
    /**
     * Menerbitkan Surat Perintah Rawat Inap (bpjs_surat_pri).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function createAdmissionOrder(array $payload): array;

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function cancelAdmissionOrder(string $orderNumber, string $reason): array;

    /**
     * Mengubah klasifikasi SEP (reklasifikasi_ralan, reklasifikasi_ranap).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function reclassifySep(array $payload): array;
}
