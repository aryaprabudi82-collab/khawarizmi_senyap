<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter Aplicares palsu, deterministik — pola yang sama seperti
 * FakeBpjsClient dan FakeBpjsReferralClient.
 *
 *  - Laporan tanpa satu pun kamar    -> ditolak BPJS (aturan nyata).
 *  - Nomor kartu diawali '8'         -> layanan sedang gangguan.
 *  - Nomor kartu diawali '9'         -> peserta tidak ditemukan.
 */
class FakeAplicaresClient implements AplicaresClient
{
    public function reportBedAvailability(array $rooms): array
    {
        if ($rooms === []) {
            return [
                'success' => false,
                'code' => '400',
                'message' => 'Tidak ada data kamar yang dikirim.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Ketersediaan kamar diterima.',
            'data' => ['diterima' => count($rooms)],
        ];
    }

    public function memberCareHistory(string $cardNumber): array
    {
        if (str_starts_with($cardNumber, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan iCare sedang gangguan.', 'data' => []];
        }

        if (str_starts_with($cardNumber, '9')) {
            return ['success' => true, 'code' => '201', 'message' => 'Peserta tidak ditemukan.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Riwayat ditemukan.',
            'data' => [
                'peserta' => ['no_kartu' => $cardNumber, 'nama' => 'Peserta JKN ' . substr($cardNumber, -4)],
                'riwayat' => [
                    ['tanggal' => now()->subMonths(2)->toDateString(), 'faskes' => 'RS Contoh', 'diagnosa' => 'I10'],
                    ['tanggal' => now()->subMonths(6)->toDateString(), 'faskes' => 'Puskesmas Contoh', 'diagnosa' => 'J06.9'],
                ],
            ],
        ];
    }
}
