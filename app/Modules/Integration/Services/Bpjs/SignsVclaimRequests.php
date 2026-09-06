<?php

namespace App\Modules\Integration\Services\Bpjs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Penandatanganan & pembacaan respons VClaim, dipakai bersama seluruh
 * adapter VClaim (domain L item A).
 *
 * DIEKSTRAK, BUKAN DISALIN. Skema tanda tangan dan dekripsi VClaim adalah
 * satu-satunya bagian yang paling mungkin salah dan paling sulit
 * ditemukan salahnya — kalau ada dua salinan, yang satu akan diperbaiki
 * saat verifikasi terhadap sandbox BPJS dan yang lain tertinggal diam-diam,
 * lalu separuh panggilan gagal dengan pesan yang tidak masuk akal.
 *
 * PERINGATAN YANG SAMA BERLAKU DI SINI: varian AES-CBC dengan kunci
 * turunan cons_id+timestamp+secret_key mengikuti dokumentasi resmi VClaim
 * yang berubah antar versi, dan WAJIB diverifikasi byte-demi-byte terhadap
 * sandbox resmi begitu kredensial faskes diterbitkan — sebelum dipakai pada
 * trafik nyata.
 */
trait SignsVclaimRequests
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    protected function signedRequest(string $method, string $path, array $body = []): array
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

            return [
                'success' => false,
                'code' => '000',
                'message' => 'Tidak dapat menghubungi VClaim: ' . $e->getMessage(),
                'data' => [],
            ];
        }

        $decoded = $this->decryptVclaimResponse($response->body(), $timestamp);

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
     * cons_id + timestamp PERMINTAAN (bukan respons) + secret_key, IV
     * memakai 16 byte nol.
     *
     * @return array<string, mixed>
     */
    protected function decryptVclaimResponse(string $rawBody, string $timestamp): array
    {
        if ($rawBody === '') {
            return [];
        }

        $key = substr(hash('sha256', $this->consId . $timestamp . $this->secretKey), 0, 32);
        $iv = str_repeat("\0", 16);

        $decrypted = openssl_decrypt(base64_decode($rawBody), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            // Bukan JSON terenkripsi yang dikenali — kembalikan kosong dan
            // catat panjangnya, biar tetap bisa dilacak dari log daripada
            // melempar exception yang menghentikan pelayanan.
            Log::channel('single')->warning('bpjs.vclaim.decrypt_failed', ['raw_length' => strlen($rawBody)]);

            return [];
        }

        return json_decode($decrypted, true) ?? [];
    }
}
