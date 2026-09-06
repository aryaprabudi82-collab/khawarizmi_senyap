<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter VClaim untuk rujukan dan surat kontrol (domain L item A).
 *
 * PERINGATAN YANG SAMA seperti BpjsVclaimClient: jalur endpoint dan bentuk
 * payload di bawah mengikuti dokumentasi VClaim yang paling umum beredar,
 * dan WAJIB diverifikasi terhadap sandbox resmi begitu kredensial faskes
 * diterbitkan — sebelum dipakai pada trafik nyata. Sampai kredensial ada,
 * IntegrationServiceProvider otomatis memakai FakeBpjsReferralClient, jadi
 * seluruh alur aplikasi tetap bisa dikerjakan dan diuji.
 *
 * Penandatanganan dan dekripsinya dipakai bersama lewat trait, bukan
 * disalin — lihat catatan pada SignsVclaimRequests.
 */
class BpjsVclaimReferralClient implements BpjsReferralClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function findReferral(string $source, string $by, string $key): array
    {
        // VClaim memisahkan endpoint rujukan PCare dan rujukan RS, dan
        // memisahkan pencarian menurut nomor / kartu / tanggal. Pemetaannya
        // dikumpulkan di satu tempat supaya pemanggil cukup tahu SATU cara
        // mencari rujukan.
        $akar = $source === self::SUMBER_RS ? '/Rujukan/RS' : '/Rujukan';

        $path = match ($by) {
            self::CARI_KARTU => "{$akar}/Peserta/{$key}",
            self::CARI_TANGGAL => "{$akar}/List/Peserta/{$key}",
            default => "{$akar}/{$key}",
        };

        return $this->signedRequest('get', $path);
    }

    public function referralHistory(string $noKartu, string $from, string $until): array
    {
        return $this->signedRequest('get', "/Rujukan/RS/List/Peserta/{$noKartu}/{$from}/{$until}");
    }

    public function createControlLetter(array $payload): array
    {
        return $this->signedRequest('post', '/RencanaKontrol/insert', [
            'request' => ['noSEP' => $payload['no_sep'] ?? null] + $payload,
        ]);
    }

    public function cancelControlLetter(string $letterNumber): array
    {
        return $this->signedRequest('delete', '/RencanaKontrol/Delete', [
            'request' => ['noSuratKontrol' => $letterNumber],
        ]);
    }

    public function createOutgoingReferral(array $payload): array
    {
        return $this->signedRequest('post', '/Rujukan/insert', ['request' => ['t_rujukan' => $payload]]);
    }
}
