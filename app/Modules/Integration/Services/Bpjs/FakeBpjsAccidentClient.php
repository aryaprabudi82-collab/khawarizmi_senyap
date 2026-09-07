<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter palsu untuk kecelakaan & Jasa Raharja, dipakai selama kredensial
 * VClaim belum ada. Aturannya sengaja dapat ditebak supaya jalur gagal
 * ikut bisa diuji:
 *
 *  - Kartu berawalan '8' mensimulasikan VClaim sedang gangguan.
 *  - Kartu berawalan '6' mensimulasikan korban yang TIDAK dijamin Jasa
 *    Raharja (misalnya kecelakaan tunggal tanpa pihak ketiga) — keadaan
 *    yang justru menentukan siapa yang menanggung tagihannya.
 *  - Selain itu dijamin, dengan plafon contoh Rp 20.000.000.
 */
class FakeBpjsAccidentClient implements BpjsAccidentClient
{
    /** Plafon contoh, BUKAN angka resmi Jasa Raharja. */
    private const PLAFON_CONTOH = 20000000;

    public function registerAccident(array $payload): array
    {
        $kartu = (string) ($payload['noKartu'] ?? '');

        if (str_starts_with($kartu, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan VClaim sedang gangguan.', 'data' => []];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Data induk kecelakaan tersimpan.',
            'data' => [
                'noRegister' => 'KLL' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            ],
        ];
    }

    public function checkJasaRaharja(string $noKartu, string $tanggalKejadian): array
    {
        if (str_starts_with($noKartu, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan VClaim sedang gangguan.', 'data' => []];
        }

        if (str_starts_with($noKartu, '6')) {
            return [
                'success' => true,
                'code' => '200',
                'message' => 'Korban tidak dijamin Jasa Raharja.',
                'data' => ['dijamin' => false],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Korban dijamin Jasa Raharja.',
            'data' => [
                'dijamin' => true,
                'noSuratJaminan' => 'JR' . now()->format('ymd') . substr($noKartu, -4),
                'plafon' => self::PLAFON_CONTOH,
                'berlakuSampai' => now()->addYear()->toDateString(),
            ],
        ];
    }

    public function createSupplement(array $payload): array
    {
        if (blank($payload['noSepAwal'] ?? null)) {
            return [
                'success' => false,
                'code' => '201',
                'message' => 'SEP awal kejadian wajib diisi untuk suplesi.',
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Suplesi diterima.',
            'data' => ['noSuplesi' => 'SPL' . now()->format('ymd') . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)],
        ];
    }
}
