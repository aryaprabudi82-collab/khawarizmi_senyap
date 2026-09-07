<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter palsu Apotek Online BPJS, dipakai selama kredensial belum ada.
 * Aturannya sengaja dapat ditebak supaya jalur gagal ikut bisa diuji:
 *
 *  - SEP berawalan 'X' mensimulasikan SEP yang tidak ditemukan — keadaan
 *    yang harus menahan resep, bukan diloloskan.
 *  - SEP berawalan 'Z' mensimulasikan ApOL sedang gangguan.
 */
class FakeBpjsApotekClient implements BpjsApotekClient
{
    public function sendPrescription(array $payload): array
    {
        $sep = (string) ($payload['noSep'] ?? '');

        if (str_starts_with($sep, 'Z')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan Apotek Online sedang gangguan.', 'data' => []];
        }

        if (blank($payload['obat'] ?? null)) {
            return ['success' => false, 'code' => '201', 'message' => 'Rincian obat wajib diisi.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Resep diterima Apotek Online.',
            'data' => [
                'noApotik' => 'APL' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            ],
        ];
    }

    public function findSep(string $sepNumber): array
    {
        if (str_starts_with($sepNumber, 'Z')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan Apotek Online sedang gangguan.', 'data' => []];
        }

        if (str_starts_with($sepNumber, 'X')) {
            return ['success' => true, 'code' => '201', 'message' => 'SEP tidak ditemukan.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'SEP ditemukan.',
            'data' => [
                'noSep' => $sepNumber,
                'noKartu' => '000' . substr($sepNumber, -10),
                'nama' => 'Peserta JKN ' . substr($sepNumber, -4),
                'tglSep' => now()->subDays(3)->toDateString(),
                'poli' => 'Penyakit Dalam',
                'diagnosa' => 'E11.9 - Diabetes Melitus Tipe 2',
            ],
        ];
    }

    public function requestIteration(array $payload): array
    {
        if (blank($payload['noApotik'] ?? null)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor resep induk wajib diisi.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Permintaan iterasi diterima.',
            'data' => [
                'noApotik' => $payload['noApotik'] . '-I' . ($payload['iterasi'] ?? 1),
            ],
        ];
    }
}
