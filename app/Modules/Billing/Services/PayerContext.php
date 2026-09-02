<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks billing menyentuh data milik konteks catalog.
 *
 * Dibaca lewat catalog.v_payer_summary — kontrak yang diterbitkan konteks
 * catalog. Yang dibutuhkan billing dari sana hanya satu hal: 'kind' penjamin,
 * dasar penentuan siapa yang menanggung tagihan.
 */
class PayerContext
{
    private const VIEW = 'catalog.v_payer_summary';

    public function find(int $payerId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $payerId)->first();
    }
}
