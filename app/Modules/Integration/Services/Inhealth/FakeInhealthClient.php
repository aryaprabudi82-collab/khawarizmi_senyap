<?php

namespace App\Modules\Integration\Services\Inhealth;

/**
 * Adapter palsu Mandiri Inhealth, dipakai selama kredensial belum ada.
 *
 * Aturannya sengaja dapat ditebak supaya jalur gagal ikut bisa diuji:
 *
 *  - Nomor peserta berawalan '8' mensimulasikan layanan gangguan.
 *  - Nomor peserta berawalan '9' mensimulasikan peserta TIDAK aktif.
 *  - Tagihan tanpa rincian ditolak, sama seperti aturan aslinya.
 */
class FakeInhealthClient implements InhealthClient
{
    public function checkEligibility(string $memberNumber, string $serviceDate): array
    {
        if (str_starts_with($memberNumber, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan Inhealth sedang gangguan.', 'data' => []];
        }

        if (str_starts_with($memberNumber, '9')) {
            return [
                'success' => true,
                'code' => '200',
                'message' => 'Peserta tidak aktif.',
                'data' => ['eligible' => false, 'nama' => 'Peserta Inhealth ' . substr($memberNumber, -4)],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Peserta aktif.',
            'data' => [
                'eligible' => true,
                'nama' => 'Peserta Inhealth ' . substr($memberNumber, -4),
                'plan' => 'Inhealth Managed Care Gold',
            ],
        ];
    }

    public function createGuarantee(array $payload): array
    {
        $peserta = (string) ($payload['noPeserta'] ?? '');

        if (str_starts_with($peserta, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan Inhealth sedang gangguan.', 'data' => []];
        }

        if (blank($payload['kodePoli'] ?? null)) {
            return ['success' => false, 'code' => '201', 'message' => 'Kode poli tujuan wajib diisi.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'SJP terbit.',
            'data' => ['noSJP' => 'SJP' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)],
        ];
    }

    public function cancelGuarantee(string $sjpNumber, string $reason): array
    {
        if (blank($sjpNumber)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor SJP wajib diisi.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'SJP dibatalkan.', 'data' => []];
    }

    public function submitBilling(array $payload): array
    {
        if (blank($payload['rincian'] ?? null)) {
            return ['success' => false, 'code' => '201', 'message' => 'Rincian tagihan wajib diisi.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Tagihan diterima.',
            'data' => ['noTagihan' => 'TGH' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)],
        ];
    }

    public function references(string $type): array
    {
        $daftar = match ($type) {
            'poli' => [
                ['kode' => 'INT', 'nama' => 'Penyakit Dalam'],
                ['kode' => 'ANA', 'nama' => 'Anak'],
            ],
            'faskes' => [
                ['kode' => 'PPK-01', 'nama' => 'RSP UI'],
            ],
            'ruang-rawat' => [
                ['kode' => 'KLS1', 'nama' => 'Kelas 1'],
                ['kode' => 'KLS2', 'nama' => 'Kelas 2'],
            ],
            default => [],
        };

        return $daftar === []
            ? ['success' => false, 'code' => '400', 'message' => "Jenis referensi '{$type}' tidak dikenal.", 'data' => []]
            : ['success' => true, 'code' => '200', 'message' => 'Referensi ditemukan.', 'data' => ['daftar' => $daftar]];
    }
}
