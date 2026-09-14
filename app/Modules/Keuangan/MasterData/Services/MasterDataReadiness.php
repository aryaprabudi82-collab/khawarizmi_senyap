<?php

namespace App\Modules\Keuangan\MasterData\Services;

use App\Modules\Keuangan\MasterData\Application\ChargeItemLinker;
use App\Modules\Keuangan\MasterData\Application\TariffSourceRegistry;
use App\Modules\ReadinessCheck;

/**
 * Syarat kesiapan sub-konteks `keuangan_master`.
 *
 * MENGAPA CAKUPAN PENAUTAN LAYAK MASUK `siap:periksa`. Sebelum ada CDM,
 * pertanyaan "apa saja yang bisa ditagih tapi belum bisa dijurnalkan"
 * tidak bisa dijawab siapa pun — jawabannya tersebar di lima tabel pada
 * lima konteks. Sekarang bisa, dan menyimpannya di dokumen berarti ia
 * tidak pernah sampai ke orang yang memutuskan tanggal operasional.
 *
 * DUA BUTIR, DAN KEDUANYA SOAL HAL BERBEDA:
 *
 *   penautan  — apakah seluruh yang bisa ditagihkan sudah punya KODE
 *   pemetaan  — apakah kode itu sudah punya AKUN
 *
 * Yang pertama bisa dibereskan sapuan; yang kedua menuntut keputusan
 * akuntansi. Menggabungkannya jadi satu butir membuat "tinggal jalankan
 * sapuan" dan "tunggu bagian keuangan memutuskan" tampak sebagai satu
 * pekerjaan yang sama.
 */
class MasterDataReadiness implements ReadinessCheck
{
    public function __construct(
        private readonly TariffSourceRegistry $registry,
        private readonly ChargeItemLinker $penaut,
    ) {}

    public function readinessItems(): array
    {
        return [
            $this->butirPenautan(),
            $this->butirPemetaanAkun(),
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirPenautan(): array
    {
        $cakupan = $this->registry->cakupan();

        $belum = array_sum(array_column($cakupan, 'belum'));
        $tertaut = array_sum(array_column($cakupan, 'tertaut'));

        if ($belum === 0 && $tertaut === 0) {
            return [
                'judul' => 'Penautan tarif ke CDM',
                'status' => self::PERINGATAN,
                'akibat' => 'Belum ada satu pun item yang bisa ditagihkan terdaftar — dan itu wajar '
                    .'selama tabel tarifnya sendiri masih kosong. Butir ini akan berbunyi begitu '
                    .'layanan, obat, atau kamar mulai diisi.',
            ];
        }

        if ($belum === 0) {
            return [
                'judul' => 'Penautan tarif ke CDM',
                'status' => self::BERES,
                'akibat' => $tertaut.' item tertaut; tidak ada sumber tarif yang tertinggal.',
            ];
        }

        $rincian = [];

        foreach ($cakupan as $konteks => $angka) {
            if ($angka['belum'] > 0) {
                $rincian[] = $konteks.': '.$angka['belum'];
            }
        }

        return [
            'judul' => 'Penautan tarif ke CDM',
            'status' => self::MENGHALANGI,
            'akibat' => $belum.' hal yang bisa ditagihkan belum punya kode CDM ('
                .implode(', ', $rincian).'). Selama belum tertaut, ia tidak punya akun pendapatan, '
                .'dan tagihan yang memuatnya TIDAK AKAN MASUK BUKU BESAR — uangnya diterima kasir '
                .'tapi tidak muncul di laporan keuangan mana pun. Jalankan penautan, lalu petakan '
                .'akunnya.',
        ];
    }

    /**
     * @return array{judul: string, status: string, akibat: string}
     */
    private function butirPemetaanAkun(): array
    {
        $ringkasan = $this->penaut->ringkasan();

        if ($ringkasan['total'] === 0) {
            return [
                'judul' => 'Pemetaan item ke akun COA',
                'status' => self::PERINGATAN,
                'akibat' => 'Belum ada item CDM sama sekali, jadi belum ada yang perlu dipetakan. '
                    .'Butir ini bergantung pada penautan di atas.',
            ];
        }

        if ($ringkasan['belum_dipetakan'] === 0) {
            return [
                'judul' => 'Pemetaan item ke akun COA',
                'status' => self::BERES,
                'akibat' => 'Seluruh '.$ringkasan['total'].' item sudah punya akun pendapatan; '
                    .$ringkasan['aktif'].' di antaranya aktif dan boleh ditagihkan.',
            ];
        }

        return [
            'judul' => 'Pemetaan item ke akun COA',
            'status' => self::MENGHALANGI,
            'akibat' => $ringkasan['belum_dipetakan'].' dari '.$ringkasan['total'].' item belum '
                .'dipetakan ke akun pendapatan, sehingga TIDAK BISA diaktifkan dan tidak bisa '
                .'ditagihkan sama sekali. Ini bukan pekerjaan teknis melainkan keputusan akuntansi: '
                .'akun mana yang menampung pendapatan tiap golongan layanan. Perlu disusun bersama '
                .'bagian keuangan, dan bergantung pada bagan akun RSP UI yang masih menunggu (Q4).',
        ];
    }
}
