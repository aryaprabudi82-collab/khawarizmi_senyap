<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks billing menyentuh data milik konteks order.
 *
 * Dibaca lewat order.v_order_charge — kontrak yang diterbitkan konteks order,
 * berisi pemeriksaan lab/radiologi yang sudah selesai dan terverifikasi
 * berikut nilainya.
 */
class OrderChargeContext
{
    private const VIEW = 'orders.v_order_charge';

    public function forRegistration(int $registrationId): Collection
    {
        return DB::table(self::VIEW)
            ->where('registration_id', $registrationId)
            ->get();
    }
}
