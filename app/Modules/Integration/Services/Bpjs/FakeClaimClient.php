<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Grouper palsu, deterministik.
 *
 * PENTING: kode CBG yang dikembalikan di sini SENGAJA DIBUAT MENCOLOK
 * palsu (diawali "X-") supaya tidak pernah tertukar dengan kode CBG asli
 * kalau sampai lolos ke lingkungan nyata. Grouper sungguhan milik
 * Kemenkes; tarif di sini tidak boleh dipakai untuk apa pun selain
 * menguji alurnya.
 *
 *  - Klaim tanpa diagnosa  -> dikembalikan grouper (aturan nyata).
 *  - Nomor SEP diawali '8' -> grouper sedang gangguan.
 *  - Selain itu dikelompokkan, tarif diturunkan dari jumlah diagnosanya
 *    supaya bisa ditebak dalam pengujian.
 */
class FakeClaimClient implements ClaimClient
{
    public function group(array $payload): array
    {
        if (str_starts_with((string) ($payload['no_sep'] ?? ''), '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Grouper sedang gangguan.', 'data' => []];
        }

        $diagnosa = $payload['diagnosa'] ?? [];

        if ($diagnosa === []) {
            return [
                'success' => false,
                'code' => '400',
                'message' => 'Klaim tanpa diagnosa tidak bisa dikelompokkan.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Klaim dikelompokkan.',
            'data' => [
                // Sengaja mencolok palsu — lihat catatan kelas.
                'kode_cbg' => 'X-' . strtoupper(substr(md5(implode(',', $diagnosa)), 0, 5)),
                'deskripsi_cbg' => 'Kelompok uji (BUKAN kode CBG asli)',
                'tarif_cbg' => count($diagnosa) * 1_000_000,
            ],
        ];
    }

    public function monitorClaims(string $scope, string $from, string $until): array
    {
        return [
            'success' => true,
            'code' => '200',
            'message' => 'Data monitoring ditemukan.',
            'data' => [
                'klaim' => [
                    ['no_sep' => 'SEP-001', 'status' => 'Verifikasi', 'tarif' => 2_500_000],
                    ['no_sep' => 'SEP-002', 'status' => 'Pending', 'tarif' => 1_500_000],
                ],
            ],
        ];
    }
}
