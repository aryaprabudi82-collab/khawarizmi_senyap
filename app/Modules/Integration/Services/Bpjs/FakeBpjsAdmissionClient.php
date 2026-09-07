<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter palsu untuk Surat PRI & reklasifikasi, dipakai selama kredensial
 * VClaim belum ada. Aturannya sengaja dapat ditebak supaya jalur gagal
 * ikut bisa diuji:
 *
 *  - Kartu berawalan '8' mensimulasikan VClaim sedang gangguan.
 *  - Reklasifikasi tanpa nomor SEP ditolak, sama seperti aturan aslinya.
 */
class FakeBpjsAdmissionClient implements BpjsAdmissionClient
{
    public function createAdmissionOrder(array $payload): array
    {
        $kartu = (string) ($payload['noKartu'] ?? '');

        if (str_starts_with($kartu, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan VClaim sedang gangguan.', 'data' => []];
        }

        if (blank($payload['tglRencanaMasuk'] ?? null)) {
            return ['success' => false, 'code' => '201', 'message' => 'Tanggal rencana masuk wajib diisi.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Surat perintah rawat inap terbit.',
            'data' => [
                'noSuratPRI' => 'PRI' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            ],
        ];
    }

    public function cancelAdmissionOrder(string $orderNumber, string $reason): array
    {
        if (blank($orderNumber)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor surat wajib diisi.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'Surat perintah rawat inap dibatalkan.', 'data' => []];
    }

    public function reclassifySep(array $payload): array
    {
        if (blank($payload['noSep'] ?? null)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor SEP wajib diisi.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Klasifikasi SEP diperbarui.',
            'data' => ['noSep' => $payload['noSep']],
        ];
    }
}
