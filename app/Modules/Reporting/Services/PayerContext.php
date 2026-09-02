<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks reporting menyentuh data milik konteks
 * catalog. Dibaca lewat catalog.v_payer_summary — dipakai mengelompokkan
 * kunjungan/pendapatan menurut jenis penjamin (umum, bpjs, asuransi,
 * perusahaan), bukan menurut penjamin satu-satu.
 */
class PayerContext
{
    private const VIEW = 'catalog.v_payer_summary';

    /** @return array<int, string> id penjamin => kind */
    public function kindsById(): array
    {
        return DB::table(self::VIEW)->pluck('kind', 'id')->all();
    }
}
