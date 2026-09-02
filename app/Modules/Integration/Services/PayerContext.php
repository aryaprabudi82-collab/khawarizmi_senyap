<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks integration menyentuh data milik konteks
 * catalog. Dibaca lewat catalog.v_payer_summary — dipakai untuk memastikan
 * SEP hanya diajukan untuk kunjungan yang penjaminnya benar BPJS.
 */
class PayerContext
{
    private const VIEW = 'catalog.v_payer_summary';

    public function find(int $payerId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $payerId)->first();
    }
}
