<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks reporting menyentuh data milik konteks
 * billing. Dibaca lewat billing.v_settled_invoice — kontrak yang
 * diterbitkan konteks billing, sudah menyertakan payer_kind sehingga tidak
 * perlu join tambahan ke catalog untuk pendapatan.
 */
class InvoiceContext
{
    private const VIEW = 'billing.v_settled_invoice';

    public function forDate(CarbonInterface $date): Collection
    {
        return DB::table(self::VIEW)->whereDate('closed_at', $date->toDateString())->get();
    }
}
