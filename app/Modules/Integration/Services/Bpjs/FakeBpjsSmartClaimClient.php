<?php

namespace App\Modules\Integration\Services\Bpjs;

use Illuminate\Support\Str;

/**
 * Adapter palsu Smart Klaim, dipakai selama kredensial belum ada.
 *
 * Bundle tanpa entri diagnosis ditolak, sama seperti aturan aslinya —
 * supaya jalur gagal ikut bisa diuji, bukan cuma jalur mulus.
 */
class FakeBpjsSmartClaimClient implements BpjsSmartClaimClient
{
    public function sendBundle(array $bundle): array
    {
        $entri = $bundle['entry'] ?? [];

        $adaDiagnosis = collect($entri)
            ->contains(fn ($e) => ($e['resource']['resourceType'] ?? null) === 'Claim'
                && ! empty($e['resource']['diagnosis'] ?? []));

        if (! $adaDiagnosis) {
            return [
                'success' => false,
                'code' => '201',
                'message' => 'Bundle klaim tanpa diagnosis ditolak.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Bundle klaim diterima Smart Klaim.',
            'data' => ['bundleId' => 'SK-' . Str::upper(Str::random(10))],
        ];
    }
}
