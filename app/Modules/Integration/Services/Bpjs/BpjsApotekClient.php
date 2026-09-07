<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter Apotek Online (ApOL) BPJS — domain L item M.
 */
interface BpjsApotekClient
{
    /**
     * Mengirim resep ke Apotek Online (bpjs_daftar_resep_apotek).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function sendPrescription(array $payload): array;

    /**
     * Mencari SEP dari sisi apotek (bpjs_kunjungan_sep_apotek).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function findSep(string $sepNumber): array;

    /**
     * Mengirim permintaan penebusan iterasi
     * (daftar_permintaan_resep_iterasi_bpjs).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function requestIteration(array $payload): array;
}
