<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\MealTime;
use App\Modules\ReadinessCheck;

/** Syarat kesiapan konteks kitchen. */
class KitchenReadiness implements ReadinessCheck
{
    public function readinessItems(): array
    {
        $aktif = MealTime::query()->where('is_active', true)->get();

        if ($aktif->isEmpty()) {
            return [[
                'judul' => 'Waktu makan pasien',
                'status' => self::PERINGATAN,
                'akibat' => 'Belum ada slot waktu makan, jadi order diet tidak bisa '
                    .'dijadwalkan pengantarannya ke bangsal. Jamnya terikat jadwal '
                    .'produksi dapur dan jadwal obat — keputusan instalasi gizi.',
            ]];
        }

        /*
         * Slot yang ADA tapi jamnya belum ditetapkan diperiksa terpisah.
         * Menghitung barisnya saja akan melaporkan "beres" padahal dapur
         * tetap tidak tahu jam berapa harus mengantar — dan kosongnya jam
         * memang sengaja dibedakan dari jam tengah malam.
         */
        $tanpaJam = $aktif->filter(fn (MealTime $m) => $m->jamBelumDitetapkan());

        return [[
            'judul' => 'Waktu makan pasien',
            'status' => $tanpaJam->isEmpty() ? self::BERES : self::PERINGATAN,
            'akibat' => $tanpaJam->isEmpty()
                ? $aktif->count().' slot terdaftar, seluruhnya berjam.'
                : $tanpaJam->count().' dari '.$aktif->count().' slot belum punya jam ('
                  .$tanpaJam->pluck('name')->implode(', ').'). Slot tanpa jam tidak bisa '
                  .'dijadwalkan pengantarannya.',
        ]];
    }
}
