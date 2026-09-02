<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter palsu, deterministik, dipakai saat kredensial VClaim BPJS belum
 * ada (lokal, uji otomatis). Aturan mainnya sengaja dibuat sederhana dan
 * dapat ditebak supaya skenario gagal juga bisa diuji, bukan cuma jalur
 * mulus:
 *
 *  - Nomor kartu yang diawali '9' dianggap tidak aktif (tidak eligible).
 *  - Nomor kartu yang diawali '8' mensimulasikan API sedang gangguan.
 *  - Selain itu dianggap eligible.
 *  - Rawat jalan (jenis_pelayanan '2') wajib punya no_rujukan, sama seperti
 *    aturan VClaim yang sesungguhnya.
 */
class FakeBpjsClient implements BpjsClient
{
    public function checkEligibility(string $noKartu, string $tanggalPelayanan): array
    {
        if (str_starts_with($noKartu, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan VClaim sedang gangguan.', 'data' => []];
        }

        if (str_starts_with($noKartu, '9')) {
            return ['success' => true, 'code' => '200', 'message' => 'Peserta tidak aktif.', 'data' => ['eligible' => false]];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Peserta ditemukan.',
            'data' => [
                'eligible' => true,
                'nama' => 'Peserta JKN ' . substr($noKartu, -4),
            ],
        ];
    }

    public function createSep(array $payload): array
    {
        if (($payload['jenisPelayanan'] ?? null) === '2' && blank($payload['noRujukan'] ?? null)) {
            return ['success' => false, 'code' => '201', 'message' => 'No. Rujukan wajib diisi untuk rawat jalan.', 'data' => []];
        }

        $sepNumber = sprintf(
            '%s%s%04d',
            now()->format('ymd'),
            str_pad((string) ($payload['poliTujuan'] ?? '000'), 3, '0', STR_PAD_LEFT),
            random_int(1, 9999)
        );

        return [
            'success' => true,
            'code' => '200',
            'message' => 'SEP berhasil diterbitkan.',
            'data' => ['noSep' => $sepNumber],
        ];
    }

    public function cancelSep(string $sepNumber, string $reason): array
    {
        if (blank($sepNumber)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor SEP wajib diisi.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'SEP berhasil dibatalkan.', 'data' => []];
    }
}
