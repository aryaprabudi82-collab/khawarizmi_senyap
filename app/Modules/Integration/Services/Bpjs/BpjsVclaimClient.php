<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter API VClaim BPJS yang sesungguhnya.
 *
 * PERINGATAN: kredensial VClaim (cons_id, secret_key, user_key) hanya
 * diterbitkan BPJS Kesehatan untuk faskes yang sudah terdaftar, dan skema
 * enkripsi tepatnya (varian AES-CBC dengan kunci turunan cons_id+timestamp+
 * secret_key di bawah ini) mengikuti dokumentasi resmi VClaim yang berubah
 * antar versi. Kelas ini mengikuti pola yang paling umum didokumentasikan,
 * tapi WAJIB diverifikasi byte-demi-byte terhadap sandbox resmi begitu
 * kredensial faskes diterbitkan, sebelum dipakai pada trafik nyata. Sebelum
 * itu, IntegrationServiceProvider otomatis memakai FakeBpjsClient.
 */
class BpjsVclaimClient implements BpjsClient
{
    // Penandatanganan & dekripsi dipakai bersama adapter rujukan sejak
    // domain L item A. Diekstrak, bukan disalin: dua salinan skema tanda
    // tangan berarti satu di antaranya tertinggal saat diverifikasi
    // terhadap sandbox BPJS, lalu separuh panggilan gagal dengan pesan
    // yang tidak masuk akal.
    use SignsVclaimRequests;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function checkEligibility(string $noKartu, string $tanggalPelayanan): array
    {
        return $this->signedRequest('get', "/Peserta/nokartu/{$noKartu}/tglSEP/{$tanggalPelayanan}");
    }

    public function createSep(array $payload): array
    {
        return $this->signedRequest('post', '/SEP/2.0/insert', $payload);
    }

    public function cancelSep(string $sepNumber, string $reason): array
    {
        return $this->signedRequest('delete', '/SEP/2.0/delete', ['noSep' => $sepNumber, 'user' => $reason]);
    }

}
