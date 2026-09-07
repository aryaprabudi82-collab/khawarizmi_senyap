<?php

namespace App\Modules\Integration\Services\Bpjs;

/**
 * Adapter palsu untuk pencarian peserta, dipakai selama kredensial VClaim
 * belum ada. Aturannya sengaja dapat ditebak supaya jalur gagal ikut bisa
 * diuji, bukan cuma jalur mulus:
 *
 *  - NIK/kartu berawalan '8' mensimulasikan VClaim sedang gangguan.
 *  - NIK/kartu berawalan '9' mensimulasikan peserta tidak ditemukan.
 *  - Kartu berawalan '7' mensimulasikan peserta yang BELUM mendaftarkan
 *    sidik jari — keadaan yang justru paling perlu terlihat di layar.
 *  - Selain itu ditemukan.
 */
class FakeBpjsMemberClient implements BpjsMemberClient
{
    public function findByNik(string $nik, string $tanggalPelayanan): array
    {
        if ($gagal = $this->gangguanAtauTidakAda($nik, 'Peserta dengan NIK tersebut tidak ditemukan.')) {
            return $gagal;
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Peserta ditemukan.',
            'data' => [
                'noKartu' => '000' . substr($nik, 0, 10),
                'nik' => $nik,
                'nama' => 'Peserta JKN ' . substr($nik, -4),
                'jenisPeserta' => 'PBI (APBN)',
                'kelasRawat' => '3',
                'statusPeserta' => 'AKTIF',
            ],
        ];
    }

    public function findSkdp(string $noSurat, string $noKartu): array
    {
        if ($gagal = $this->gangguanAtauTidakAda($noKartu, 'SKDP tidak ditemukan.')) {
            return $gagal;
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'SKDP ditemukan.',
            'data' => [
                'noSurat' => $noSurat,
                'noKartu' => $noKartu,
                'nama' => 'Peserta JKN ' . substr($noKartu, -4),
                'tglRencanaKontrol' => now()->addWeek()->toDateString(),
                'namaDokter' => 'dr. Kontrol Uji',
                'poliTujuan' => 'Penyakit Dalam',
            ],
        ];
    }

    public function serviceHistory(string $noKartu, string $from, string $until): array
    {
        if ($gagal = $this->gangguanAtauTidakAda($noKartu, 'Riwayat pelayanan tidak ditemukan.')) {
            return $gagal;
        }

        return [
            'success' => true,
            'code' => '200',
            'message' => 'Riwayat pelayanan ditemukan.',
            'data' => [
                'histori' => [
                    [
                        'noSep' => '0301R00' . substr($noKartu, -6),
                        'tglSep' => $from,
                        'jnsPelayanan' => 'Rawat Jalan',
                        'ppkPelayanan' => 'RSUD Pembanding',
                        'diagnosa' => 'E11.9 - Diabetes Melitus Tipe 2',
                    ],
                    [
                        'noSep' => '0301R01' . substr($noKartu, -6),
                        'tglSep' => $until,
                        'jnsPelayanan' => 'Rawat Inap',
                        'ppkPelayanan' => 'RS Swasta Pembanding',
                        'diagnosa' => 'I10 - Hipertensi Esensial',
                    ],
                ],
            ],
        ];
    }

    public function fingerprintStatus(string $noKartu, string $tanggalPelayanan): array
    {
        if ($gagal = $this->gangguanAtauTidakAda($noKartu, 'Peserta tidak ditemukan.')) {
            return $gagal;
        }

        $terdaftar = ! str_starts_with($noKartu, '7');

        return [
            'success' => true,
            'code' => '200',
            'message' => $terdaftar ? 'Peserta sudah terdaftar sidik jari.' : 'Peserta belum terdaftar sidik jari.',
            'data' => [
                'noKartu' => $noKartu,
                'terdaftar' => $terdaftar,
                'tglDaftar' => $terdaftar ? now()->subMonths(3)->toDateString() : null,
            ],
        ];
    }

    /**
     * @return array{success: bool, code: string, message: string, data: array<string, mixed>}|null
     */
    private function gangguanAtauTidakAda(string $kunci, string $pesanTidakAda): ?array
    {
        if (str_starts_with($kunci, '8')) {
            return ['success' => false, 'code' => '500', 'message' => 'Layanan VClaim sedang gangguan.', 'data' => []];
        }

        if (str_starts_with($kunci, '9')) {
            return ['success' => true, 'code' => '201', 'message' => $pesanTidakAda, 'data' => []];
        }

        return null;
    }
}
