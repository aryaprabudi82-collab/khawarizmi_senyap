<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter rujukan palsu, deterministik, dipakai selama kredensial VClaim
 * belum ada — pola yang sama seperti FakeBpjsClient.
 *
 * Aturannya sengaja bisa ditebak supaya JALUR GAGAL juga bisa diuji,
 * bukan cuma jalur mulus:
 *
 *  - Kunci pencarian yang diawali '9' -> rujukan tidak ditemukan.
 *  - Kunci yang diawali '8'           -> VClaim sedang gangguan.
 *  - Rujukan lebih dari 90 hari       -> ditemukan tapi KEDALUWARSA,
 *                                        mencerminkan aturan VClaim yang
 *                                        sesungguhnya bahwa rujukan hanya
 *                                        berlaku 90 hari.
 *  - Selain itu ditemukan dan berlaku.
 */
class FakeBpjsReferralClient implements BpjsReferralClient
{
    public function findReferral(string $source, string $by, string $key): array
    {
        if ($gangguan = $this->gangguan($key)) {
            return $gangguan;
        }

        if (str_starts_with($key, '9')) {
            return [
                'success' => true,
                'code' => '201',
                'message' => 'Data rujukan tidak ditemukan.',
                'data' => [],
            ];
        }

        // Rujukan yang sengaja dibuat lama, supaya aturan 90 hari teruji.
        $lama = str_starts_with($key, '7');
        $tanggal = $lama ? now()->subDays(120) : now()->subDays(3);

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Rujukan ditemukan.',
            'data' => [
                'no_rujukan' => $by === self::CARI_NOMOR ? $key : 'RJK-' . substr($key, -6),
                'sumber' => $source,
                'tanggal_rujukan' => $tanggal->toDateString(),
                'no_kartu' => $by === self::CARI_KARTU ? $key : '000' . substr($key, -10),
                'nama_peserta' => 'Peserta JKN ' . substr($key, -4),
                'faskes_perujuk' => $source === self::SUMBER_PCARE ? 'Puskesmas Contoh' : 'RS Contoh',
                'diagnosa' => 'Z00.0 - Pemeriksaan umum',
                'poli_tujuan' => 'INT',
            ],
        ];
    }

    public function referralHistory(string $noKartu, string $from, string $until): array
    {
        if ($gangguan = $this->gangguan($noKartu)) {
            return $gangguan;
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Riwayat ditemukan.',
            'data' => [
                'rujukan' => [
                    [
                        'no_rujukan' => 'RJK-' . substr($noKartu, -6),
                        'tanggal_rujukan' => $from,
                        'faskes_tujuan' => 'RS Contoh',
                        'diagnosa' => 'Z00.0 - Pemeriksaan umum',
                    ],
                ],
            ],
        ];
    }

    public function createControlLetter(array $payload): array
    {
        if ($gangguan = $this->gangguan((string) ($payload['no_sep'] ?? ''))) {
            return $gangguan;
        }

        // VClaim menolak surat kontrol tanpa SEP asal — aturan yang sama
        // ditiru di sini supaya penanganannya benar-benar teruji.
        if (empty($payload['no_sep'])) {
            return [
                'success' => false,
                'code' => '400',
                'message' => 'Nomor SEP asal wajib diisi.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Surat kontrol terbit.',
            'data' => [
                'no_surat' => 'SK-' . now()->format('Ymd') . '-' . substr(md5((string) $payload['no_sep']), 0, 5),
                'tanggal_rencana' => $payload['tanggal_rencana'] ?? null,
            ],
        ];
    }

    public function cancelControlLetter(string $letterNumber): array
    {
        if (str_starts_with($letterNumber, '9')) {
            return ['success' => false, 'code' => '201', 'message' => 'Surat kontrol tidak ditemukan.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'Surat kontrol dibatalkan.', 'data' => []];
    }

    public function createOutgoingReferral(array $payload): array
    {
        if ($gangguan = $this->gangguan((string) ($payload['no_sep'] ?? ''))) {
            return $gangguan;
        }

        if (empty($payload['faskes_tujuan'])) {
            return [
                'success' => false,
                'code' => '400',
                'message' => 'Faskes tujuan wajib diisi.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Rujukan keluar terbit.',
            'data' => [
                'no_rujukan' => 'RJKO-' . now()->format('Ymd') . '-' . substr(md5((string) $payload['no_sep']), 0, 5),
            ],
        ];
    }

    /** @return array{success: bool, code: string, message: string, data: array<string, mixed>}|null */
    private function gangguan(string $key): ?array
    {
        if (! str_starts_with($key, '8')) {
            return null;
        }

        return ['success' => false, 'code' => '500', 'message' => 'Layanan VClaim sedang gangguan.', 'data' => []];
    }
}
