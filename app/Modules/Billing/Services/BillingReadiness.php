<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\CashierShift;
use App\Modules\ReadinessCheck;

/** Syarat kesiapan konteks billing. */
class BillingReadiness implements ReadinessCheck
{
    public function readinessItems(): array
    {
        $jumlah = CashierShift::query()->where('is_active', true)->count();

        return [[
            'judul' => 'Shift kasir',
            'status' => $jumlah > 0 ? self::BERES : self::MENGHALANGI,
            'akibat' => $jumlah > 0
                ? $jumlah.' shift terdaftar.'
                : 'Belum ada satu pun shift kasir. Penutupan shift TIDAK BISA dijalankan '
                  .'sama sekali, jadi tidak ada yang mencocokkan uang di laci dengan '
                  .'pembayaran yang tercatat sistem — sepanjang hari, setiap hari. '
                  .'Selisih kas baru ketahuan saat ada yang menghitung manual.',
        ]];
    }
}
