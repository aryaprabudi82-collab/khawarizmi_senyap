<?php

namespace App\Modules\Integration\Services\Sisrute;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Sisrute Kemenkes (domain L item P).
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter sistem luar di modul ini:
 * jalur endpoint dan bentuk payload di bawah mengikuti dokumentasi yang
 * beredar dan WAJIB diverifikasi terhadap lingkungan uji resmi begitu
 * kredensial faskes diterbitkan. Sampai kredensial ada,
 * IntegrationServiceProvider otomatis memakai FakeSisruteClient.
 *
 * SISRUTE MEMAKAI SKEMA OTENTIKASI SENDIRI, bukan tanda tangan VClaim —
 * karena itu trait SignsVclaimRequests TIDAK dipakai di sini. Memaksakan
 * skema yang salah akan menghasilkan kegagalan yang tampak seperti
 * kredensial salah, dan waktu paling banyak terbuang mencari di tempat
 * yang keliru.
 */
class HttpSisruteClient implements SisruteClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $facilityCode,
    ) {}

    public function sendReferral(array $payload): array
    {
        return $this->request('post', '/rujukan/keluar', $payload + ['kodeFaskesAsal' => $this->facilityCode]);
    }

    public function cancelReferral(string $sisruteNumber, string $reason): array
    {
        return $this->request('post', '/rujukan/batal', [
            'noRujukan' => $sisruteNumber,
            'alasan' => $reason,
        ]);
    }

    public function fetchIncoming(string $from, string $until): array
    {
        return $this->request('get', "/rujukan/masuk/{$this->facilityCode}/{$from}/{$until}");
    }

    public function respondIncoming(array $payload): array
    {
        return $this->request('post', '/rujukan/jawab', $payload + ['kodeFaskesTujuan' => $this->facilityCode]);
    }

    public function references(string $type): array
    {
        return $this->request('get', "/referensi/{$type}");
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}
     */
    private function request(string $method, string $path, array $body = []): array
    {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->timeout(15)
                ->{$method}($this->baseUrl . $path, $body);
        } catch (\Throwable $e) {
            // Galat jaringan DICATAT sebagai kegagalan yang bisa dibaca,
            // bukan dilempar keluar: pasien yang sedang menunggu tempat
            // tidak boleh kehilangan jejak permintaannya karena koneksi.
            Log::channel('single')->error('sisrute.request_failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => '000',
                'message' => 'Tidak dapat menghubungi Sisrute: ' . $e->getMessage(),
                'data' => [],
            ];
        }

        $decoded = $response->json() ?? [];

        return [
            'success' => $response->successful() && ($decoded['metadata']['code'] ?? '200') === '200',
            'code' => (string) ($decoded['metadata']['code'] ?? $response->status()),
            'message' => (string) ($decoded['metadata']['message'] ?? 'Tidak ada pesan dari Sisrute.'),
            'data' => (array) ($decoded['response'] ?? $decoded['data'] ?? []),
        ];
    }
}
