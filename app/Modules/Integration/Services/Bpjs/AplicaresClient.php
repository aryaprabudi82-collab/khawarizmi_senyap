<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Kontrak adapter Aplicares & iCare BPJS (domain L item B).
 *
 * Dipisah dari BpjsClient dan BpjsReferralClient dengan alasan yang sama:
 * antarmuka yang menampung seluruh permukaan API BPJS akan selalu
 * diimplementasikan setengah-setengah.
 */
interface AplicaresClient
{
    /**
     * Melaporkan ketersediaan tempat tidur.
     *
     * @param  array<int, array{kode_kelas: string, tersedia: int, terisi: int}>  $rooms
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function reportBedAvailability(array $rooms): array;

    /**
     * Riwayat perawatan peserta menurut BPJS (iCare).
     *
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    public function memberCareHistory(string $cardNumber): array;
}
