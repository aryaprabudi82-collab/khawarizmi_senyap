<?php

namespace App\Modules\Integration\Services\Sisrute;

/**
 * Adapter palsu Sisrute, dipakai selama kredensial belum ada.
 *
 * Aturannya sengaja dapat ditebak supaya jalur gagal ikut bisa diuji:
 *
 *  - Kode faskes tujuan berawalan 'Z' mensimulasikan Sisrute gangguan.
 *  - Kode faskes tujuan berawalan 'P' mensimulasikan rumah sakit yang
 *    PENUH — jawaban yang justru paling sering terjadi di lapangan, dan
 *    yang paling perlu terlihat jelas oleh perujuk.
 */
class FakeSisruteClient implements SisruteClient
{
    public function sendReferral(array $payload): array
    {
        $tujuan = (string) ($payload['kodeFaskesTujuan'] ?? '');

        if (str_starts_with($tujuan, 'Z')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan Sisrute sedang gangguan.', 'data' => []];
        }

        if (blank($payload['ringkasanKlinis'] ?? null)) {
            return [
                'success' => false,
                'code' => '201',
                'message' => 'Ringkasan kondisi pasien wajib diisi.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Rujukan diajukan.',
            'data' => [
                'noRujukan' => 'SR' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                // Rumah sakit berkode 'P' menjawab penuh sejak awal.
                'statusTujuan' => str_starts_with($tujuan, 'P') ? 'penuh' : 'menunggu',
            ],
        ];
    }

    public function cancelReferral(string $sisruteNumber, string $reason): array
    {
        if (blank($sisruteNumber)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor rujukan Sisrute wajib diisi.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'Rujukan ditarik.', 'data' => []];
    }

    public function fetchIncoming(string $from, string $until): array
    {
        return [
            'success' => true,
            'code' => '200',
            'message' => 'Rujukan masuk ditemukan.',
            'data' => [
                'rujukan' => [
                    [
                        'noRujukan' => 'SR' . now()->format('ymd') . '0001',
                        'namaPasien' => 'Pasien Rujukan Masuk',
                        'nik' => '3175010101800001',
                        'tglLahir' => '1980-01-01',
                        'jenisKelamin' => 'L',
                        'kodeFaskesAsal' => 'RS-ASAL-01',
                        'namaFaskesAsal' => 'RSUD Perujuk',
                        'kodeAlasan' => 'SPESIALIS',
                        'diagnosa' => 'I21.9',
                        'ringkasanKlinis' => 'Nyeri dada khas iskemik, EKG ST elevasi.',
                        'tglRujukan' => now()->toDateTimeString(),
                    ],
                ],
            ],
        ];
    }

    public function respondIncoming(array $payload): array
    {
        if (blank($payload['noRujukan'] ?? null)) {
            return ['success' => false, 'code' => '400', 'message' => 'Nomor rujukan wajib diisi.', 'data' => []];
        }

        return ['success' => true, 'code' => '200', 'message' => 'Jawaban terkirim.', 'data' => []];
    }

    public function references(string $type): array
    {
        $daftar = match ($type) {
            'alasan-rujuk' => [
                ['kode' => 'SPESIALIS', 'nama' => 'Memerlukan pelayanan spesialis'],
                ['kode' => 'SARANA', 'nama' => 'Keterbatasan sarana dan prasarana'],
                ['kode' => 'PENUH', 'nama' => 'Kapasitas ruang rawat penuh'],
            ],
            'diagnosa' => [
                ['kode' => 'I21.9', 'nama' => 'Infark Miokard Akut'],
                ['kode' => 'J18.9', 'nama' => 'Pneumonia'],
            ],
            'faskes' => [
                ['kode' => 'RS-TUJUAN-01', 'nama' => 'RSUP Rujukan Nasional'],
                ['kode' => 'RS-TUJUAN-02', 'nama' => 'RSUD Rujukan Provinsi'],
            ],
            default => [],
        };

        return $daftar === []
            ? ['success' => false, 'code' => '400', 'message' => "Jenis referensi '{$type}' tidak dikenal.", 'data' => []]
            : ['success' => true, 'code' => '200', 'message' => 'Referensi ditemukan.', 'data' => ['daftar' => $daftar]];
    }
}
