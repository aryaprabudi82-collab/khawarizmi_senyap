<?php

namespace App\Modules\Integration\Services\Bpjs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consId,
        private readonly string $secretKey,
        private readonly string $userKey,
    ) {}

    public function checkEligibility(string $noKartu, string $tanggalPelayanan): array
    {
        return $this->request('get', "/Peserta/nokartu/{$noKartu}/tglSEP/{$tanggalPelayanan}");
    }

    public function createSep(array $payload): array
    {
        return $this->request('post', '/SEP/2.0/insert', $payload);
    }

    public function cancelSep(string $sepNumber, string $reason): array
    {
        return $this->request('delete', '/SEP/2.0/delete', ['noSep' => $sepNumber, 'user' => $reason]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $timestamp = (string) (time() - strtotime('1970-01-01 00:00:00 UTC'));
        $signature = base64_encode(hash_hmac('sha256', "{$this->consId}&{$timestamp}", $this->secretKey, true));

        $headers = [
            'X-cons-id' => $this->consId,
            'X-timestamp' => $timestamp,
            'X-signature' => $signature,
            'user_key' => $this->userKey,
            'Content-Type' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->{$method}($this->baseUrl . $path, $body);
        } catch (\Throwable $e) {
            Log::channel('single')->error('bpjs.vclaim.request_failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'code' => '000', 'message' => 'Tidak dapat menghubungi VClaim: ' . $e->getMessage(), 'data' => []];
        }

        $decoded = $this->decryptResponse($response->body(), $timestamp);

        $metaCode = (string) ($decoded['metadata']['code'] ?? $response->status());

        return [
            'success' => $metaCode === '200',
            'code' => $metaCode,
            'message' => (string) ($decoded['metadata']['message'] ?? 'Tidak ada pesan dari VClaim.'),
            'data' => (array) ($decoded['response'] ?? []),
        ];
    }

    /**
     * Body respons VClaim dienkripsi AES-256-CBC. Kunci diturunkan dari
     * cons_id + timestamp permintaan + secret_key (bukan dari respons),
     * IV memakai 16 byte nol — sebagaimana didokumentasikan BPJS untuk versi
     * API ini. Lihat catatan verifikasi di docblock kelas.
     *
     * @return array<string, mixed>
     */
    private function decryptResponse(string $rawBody, string $timestamp): array
    {
        if ($rawBody === '') {
            return [];
        }

        $key = substr(hash('sha256', $this->consId . $timestamp . $this->secretKey), 0, 32);
        $iv = str_repeat("\0", 16);

        $decrypted = openssl_decrypt(base64_decode($rawBody), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            // Bukan JSON terenkripsi yang dikenali — kembalikan body mentah
            // biar tetap bisa dilacak dari log, daripada melempar exception.
            Log::channel('single')->warning('bpjs.vclaim.decrypt_failed', ['raw_length' => strlen($rawBody)]);

            return [];
        }

        return json_decode($decrypted, true) ?? [];
    }
}
