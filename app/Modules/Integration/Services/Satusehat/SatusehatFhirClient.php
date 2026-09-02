<?php

namespace App\Modules\Integration\Services\Satusehat;

use App\Modules\Integration\Models\SatusehatToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter SATUSEHAT (Kemenkes) yang sesungguhnya: OAuth2 client-credentials
 * lalu REST FHIR R4 biasa (POST untuk create, PUT/{id} untuk update).
 *
 * Token disimpan di integration.satusehat_tokens supaya tidak perlu meminta
 * token baru di setiap panggilan — SATUSEHAT membatasi laju permintaan
 * token per client_id. Kredensial (client_id, client_secret, organization_id
 * lokasi faskes) hanya diterbitkan Kemenkes untuk faskes yang sudah
 * terverifikasi; sampai saat itu IntegrationServiceProvider memakai
 * FakeSatusehatClient.
 */
class SatusehatFhirClient implements SatusehatClient
{
    private const TOKEN_KEY = 'default';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $organizationId,
    ) {}

    public function putResource(string $resourceType, ?string $existingId, array $resource): array
    {
        $token = $this->accessToken();

        if ($token === null) {
            return ['success' => false, 'resource_id' => null, 'message' => 'Gagal mendapatkan token SATUSEHAT.', 'response' => []];
        }

        $url = rtrim($this->baseUrl, '/') . '/' . $resourceType . ($existingId !== null ? '/' . $existingId : '');

        try {
            $http = Http::withToken($token)->acceptJson()->timeout(15);
            $response = $existingId !== null ? $http->put($url, $resource) : $http->post($url, $resource);
        } catch (\Throwable $e) {
            Log::channel('single')->error('satusehat.request_failed', ['resourceType' => $resourceType, 'error' => $e->getMessage()]);

            return ['success' => false, 'resource_id' => null, 'message' => 'Tidak dapat menghubungi SATUSEHAT: ' . $e->getMessage(), 'response' => []];
        }

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            $message = $body['issue'][0]['diagnostics'] ?? $response->reason();

            return ['success' => false, 'resource_id' => null, 'message' => (string) $message, 'response' => $body];
        }

        return [
            'success' => true,
            'resource_id' => $body['id'] ?? $existingId,
            'message' => $existingId === null ? 'Resource dibuat.' : 'Resource diperbarui.',
            'response' => $body,
        ];
    }

    private function accessToken(): ?string
    {
        $cached = SatusehatToken::query()->find(self::TOKEN_KEY);

        if ($cached !== null && $cached->expires_at->isFuture()) {
            return $cached->access_token;
        }

        try {
            $response = Http::asForm()->timeout(15)->post($this->authUrl, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->error('satusehat.token_request_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('single')->error('satusehat.token_request_rejected', ['status' => $response->status()]);

            return null;
        }

        $body = $response->json();
        $token = $body['access_token'] ?? null;
        $expiresIn = (int) ($body['expires_in'] ?? 0);

        if ($token === null || $expiresIn <= 0) {
            return null;
        }

        // Berhenti 60 detik lebih awal dari kedaluwarsa sesungguhnya, jadi
        // permintaan yang sudah berjalan tidak ketiban token yang expire
        // di tengah jalan.
        SatusehatToken::query()->updateOrCreate(
            ['key' => self::TOKEN_KEY],
            ['access_token' => $token, 'expires_at' => now()->addSeconds(max(0, $expiresIn - 60)), 'obtained_at' => now()]
        );

        return $token;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
