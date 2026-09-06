<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter grouper INA-CBG & monitoring klaim yang sesungguhnya.
 *
 * PERINGATAN, dan yang ini lebih berat daripada adapter lain: E-Klaim
 * (grouper INA-CBG) adalah aplikasi terpisah milik Kemenkes dengan
 * antarmuka dan skema enkripsinya SENDIRI, berbeda dari VClaim. Jalur dan
 * bentuk payload di bawah mengikuti pola yang paling umum beredar, dan
 * WAJIB diverifikasi terhadap instalasi E-Klaim yang sungguhan sebelum
 * dipakai — kesalahan di sini bukan sekadar laporan meleset, melainkan
 * klaim yang ditolak atau tarif yang salah.
 *
 * Monitoring klaim memakai VClaim, jadi penandatanganannya sama dan
 * dipakai bersama lewat trait. Grouper mungkin TIDAK memakai skema itu;
 * pemisahannya sengaja dibuat terlihat di kode, bukan disamarkan.
 */
class BpjsClaimClient implements ClaimClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
        private readonly string $ppkCode,
    ) {}

    public function group(array $payload): array
    {
        // Endpoint grouper E-Klaim. Bentuk permintaannya berbeda dari
        // VClaim dan wajib diverifikasi terhadap instalasi nyata.
        return $this->signedRequest('post', '/eklaim/grouper', [
            'metadata' => ['method' => 'grouper_stage_1'],
            'data' => $payload + ['kode_rs' => $this->ppkCode],
        ]);
    }

    public function monitorClaims(string $scope, string $from, string $until): array
    {
        $jalur = $scope === 'apotek' ? 'Monitoring/Klaim/Apotek' : 'Monitoring/Klaim';

        return $this->signedRequest('get', "/{$jalur}/tglMulai/{$from}/tglAkhir/{$until}");
    }
}
