<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penomoran atomik lewat INSERT ... ON CONFLICT DO UPDATE ... RETURNING,
 * pola yang sama dipakai identity/encounter/pharmacy/billing/quality —
 * bukan SELECT MAX(...)+1 yang berisiko kembar saat beberapa permintaan
 * bersamaan.
 *
 * Diekstrak dari CostEstimateService::allocateNumber() saat domain K item
 * A butuh penomoran yang sama; logikanya tidak diubah, cuma dipindahkan
 * supaya tidak ada dua salinan yang bisa berbeda kelak.
 *
 * Kuncinya menyertakan tanggal (default) atau tahun, sehingga nomor
 * mengulang dari 1 tiap periode tanpa perlu reset terjadwal.
 */
class NumberAllocator
{
    public function allocate(string $prefix, string $periodFormat = 'Ymd'): string
    {
        $key = $prefix . now()->format($periodFormat);

        $row = DB::selectOne(
            'INSERT INTO finance.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = finance.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
