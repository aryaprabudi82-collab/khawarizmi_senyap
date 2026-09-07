<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter VClaim untuk pencarian & riwayat peserta (domain L item J).
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter VClaim lain: jalur endpoint
 * dan bentuk payload di bawah mengikuti dokumentasi yang paling umum
 * beredar, dan WAJIB diverifikasi terhadap sandbox resmi begitu kredensial
 * faskes diterbitkan — sebelum dipakai pada trafik nyata. Sampai kredensial
 * ada, IntegrationServiceProvider otomatis memakai FakeBpjsMemberClient.
 *
 * Penandatanganan dan dekripsinya dipakai bersama lewat trait, bukan
 * disalin — lihat catatan pada SignsVclaimRequests.
 */
class BpjsVclaimMemberClient implements BpjsMemberClient
{
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function findByNik(string $nik, string $tanggalPelayanan): array
    {
        return $this->signedRequest('get', "/Peserta/nik/{$nik}/tglSEP/{$tanggalPelayanan}");
    }

    public function findSkdp(string $noSurat, string $noKartu): array
    {
        return $this->signedRequest('get', "/RencanaKontrol/nosuratkontrol/{$noSurat}/nokartu/{$noKartu}");
    }

    public function serviceHistory(string $noKartu, string $from, string $until): array
    {
        return $this->signedRequest('get', "/monitoring/HistoriPelayanan/NoKartu/{$noKartu}/tglMulai/{$from}/tglAkhir/{$until}");
    }

    public function fingerprintStatus(string $noKartu, string $tanggalPelayanan): array
    {
        return $this->signedRequest('get', "/Peserta/fingerprint/Peserta/{$noKartu}/TglPelayanan/{$tanggalPelayanan}");
    }
}
