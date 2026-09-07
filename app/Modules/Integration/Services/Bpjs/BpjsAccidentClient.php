<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter VClaim untuk kecelakaan & Jasa Raharja (domain L item K).
 */
interface BpjsAccidentClient
{
    /**
     * Mendaftarkan data induk kecelakaan (bpjs_data_induk_kecelakaan).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function registerAccident(array $payload): array;

    /**
     * Menanyakan penjaminan Jasa Raharja atas satu kejadian
     * (bpjs_klaim_jasa_raharja).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function checkJasaRaharja(string $noKartu, string $tanggalKejadian): array;

    /**
     * Mengajukan suplesi untuk kunjungan lanjutan atas kejadian yang sama
     * (bpjs_suplesi_jasaraharja).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function createSupplement(array $payload): array;
}
