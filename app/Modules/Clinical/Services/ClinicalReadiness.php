<?php

namespace App\Modules\Clinical\Services;

use App\Modules\ReadinessCheck;
use Illuminate\Support\Facades\DB;

/**
 * Syarat kesiapan konteks clinical.
 *
 * DITEMUKAN SAAT VERIFIKASI DOMAIN M: kamus ICD-10 yang terpasang hanya
 * berisi contoh untuk pengembangan. Seedernya jujur menyatakan itu, tapi
 * kejujuran yang hidup di komentar kode tidak sampai ke orang yang membuka
 * layanan.
 */
class ClinicalReadiness implements ReadinessCheck
{
    /**
     * Di bawah angka ini, kamus yang terpasang jelas bukan ICD-10 sungguhan.
     *
     * ICD-10 resmi berisi puluhan ribu kode. Ambangnya sengaja dipasang
     * rendah dan kasar — yang hendak dibedakan bukan "lengkap" dari "hampir
     * lengkap", melainkan "kamus sungguhan" dari "belasan contoh untuk
     * menjalankan uji".
     */
    private const AMBANG_KAMUS_SUNGGUHAN = 500;

    public function readinessItems(): array
    {
        $jumlah = DB::table('clinical.diagnosis_codes')->count();

        if ($jumlah >= self::AMBANG_KAMUS_SUNGGUHAN) {
            return [[
                'judul' => 'Kamus diagnosis ICD-10',
                'status' => self::BERES,
                'akibat' => number_format($jumlah, 0, ',', '.').' kode terpasang.',
            ]];
        }

        return [[
            'judul' => 'Kamus diagnosis ICD-10',
            'status' => self::MENGHALANGI,
            'akibat' => 'Hanya '.$jumlah.' kode diagnosis terpasang — itu contoh untuk '
                .'pengembangan, bukan kamus sungguhan (ICD-10 resmi berisi puluhan ribu '
                .'kode). Dokter tidak akan menemukan sebagian besar diagnosis yang '
                .'ditegakkannya, dan diagnosis yang tidak terkode tidak masuk laporan RL '
                .'4A/4B maupun klaim BPJS. Kamus resminya diimpor terpisah dari rilis '
                .'WHO/Kemenkes.',
        ]];
    }
}
