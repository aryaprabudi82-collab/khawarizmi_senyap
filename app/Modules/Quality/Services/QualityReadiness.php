<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraRiskItem;
use App\Modules\ReadinessCheck;

/** Syarat kesiapan konteks quality. */
class QualityReadiness implements ReadinessCheck
{
    public function readinessItems(): array
    {
        $area = IcraArea::query()->count();
        $butir = IcraRiskItem::query()->where('is_active', true)->count();

        return [
            [
                'judul' => 'Area & kelompok risiko ICRA',
                'status' => $area > 0 ? self::BERES : self::PERINGATAN,
                'akibat' => $area > 0
                    ? $area.' area terdaftar.'
                    : 'Belum ada area terdaftar. Kajian ICRA pra-konstruksi tidak bisa '
                      .'dibuka sama sekali, karena kelas pencegahannya DIHITUNG dari '
                      .'persilangan tipe aktivitas proyek dengan kelompok risiko area '
                      .'terdampak — tanpa area, tidak ada yang bisa disilangkan.',
            ],
            [
                'judul' => 'Butir daftar periksa risiko ICRA',
                'status' => $butir > 0 ? self::BERES : self::PERINGATAN,
                'akibat' => $butir > 0
                    ? $butir.' butir terdaftar.'
                    : 'Daftar periksa risiko kosong, jadi kajian ICRA bisa disimpulkan '
                      .'tanpa satu pun butir yang benar-benar diperiksa — dan dokumennya '
                      .'justru jadi bukti bahwa rumah sakit sudah menilai. Disusun IPCN.',
            ],
        ];
    }
}
