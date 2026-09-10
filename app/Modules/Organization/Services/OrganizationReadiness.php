<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\OperatingRoom;
use App\Modules\ReadinessCheck;

/** Syarat kesiapan konteks organization. */
class OrganizationReadiness implements ReadinessCheck
{
    public function readinessItems(): array
    {
        $jumlah = OperatingRoom::query()->where('is_active', true)->count();

        return [[
            'judul' => 'Ruang operasi',
            'status' => $jumlah > 0 ? self::BERES : self::PERINGATAN,
            'akibat' => $jumlah > 0
                ? $jumlah.' ruang terdaftar.'
                : 'Belum ada ruang operasi terdaftar, jadi jadwal maupun laporan operasi '
                  .'tidak bisa menyebut ruangnya. Kolom kamar operasi pada laporan RL 3.6 '
                  .'yang dikirim ke Kemenkes akan kosong. Daftarnya kenyataan fisik gedung '
                  .'RSP UI — tidak ada yang bisa menebaknya dari luar.',
        ]];
    }
}
