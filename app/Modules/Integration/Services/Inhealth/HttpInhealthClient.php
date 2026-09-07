<?php

namespace App\Modules\Integration\Services\Inhealth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Mandiri Inhealth (domain L item Q).
 *
 * PERINGATAN YANG SAMA seperti seluruh adapter sistem luar di modul ini:
 * jalur endpoint dan bentuk payload mengikuti dokumentasi yang beredar dan
 * WAJIB diverifikasi terhadap lingkungan uji resmi begitu kredensial
 * diterbitkan. Sampai kredensial ada, IntegrationServiceProvider memakai
 * FakeInhealthClient.
 *
 * INHEALTH MEMAKAI OTENTIKASI SENDIRI, bukan skema tanda tangan VClaim —
 * karena itu SignsVclaimRequests tidak dipakai di sini. Memaksakan skema
 * yang salah menghasilkan kegagalan yang tampak seperti kredensial salah.
 */
class HttpInhealthClient implements InhealthClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $providerCode,
    ) {}

    public function checkEligibility(string $memberNumber, string $serviceDate): array
    {
        return $this->request('get', "/peserta/{$memberNumber}/tglPelayanan/{$serviceDate}");
    }

    public function createGuarantee(array $payload): array
    {
        return $this->request('post', '/sjp/insert', $payload + ['kodeProvider' => $this->providerCode]);
    }

    public function cancelGuarantee(string $sjpNumber, string $reason): array
    {
        return $this->request('post', '/sjp/delete', ['noSJP' => $sjpNumber, 'alasan' => $reason]);
    }

    public function submitBilling(array $payload): array
    {
        return $this->request('post', '/tagihan/insert', $payload + ['kodeProvider' => $this->providerCode]);
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
            Log::channel('single')->error('inhealth.request_failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => '000',
                'message' => 'Tidak dapat menghubungi Inhealth: ' . $e->getMessage(),
                'data' => [],
            ];
        }

        $decoded = $response->json() ?? [];

        return [
            'success' => $response->successful() && (string) ($decoded['metaData']['code'] ?? '200') === '200',
            'code' => (string) ($decoded['metaData']['code'] ?? $response->status()),
            'message' => (string) ($decoded['metaData']['message'] ?? 'Tidak ada pesan dari Inhealth.'),
            'data' => (array) ($decoded['response'] ?? $decoded['data'] ?? []),
        ];
    }
}
