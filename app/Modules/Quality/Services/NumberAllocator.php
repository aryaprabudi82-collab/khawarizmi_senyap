<?php

namespace App\Modules\Quality\Services;

use Illuminate\Support\Facades\DB;

/**
 * Penomoran atomik lewat INSERT ... ON CONFLICT DO UPDATE ... RETURNING,
 * pola yang sama dipakai identity/encounter/pharmacy/billing — bukan
 * SELECT MAX(...)+1 yang berisiko kembar saat beberapa permintaan
 * bersamaan.
 *
 * Nomor mengulang dari 1 tiap tahun karena tahun ikut jadi bagian kunci
 * (prefix "IKP-2026" dan "IKP-2027" adalah baris counter yang berbeda),
 * bukan lewat reset terjadwal — konsisten dengan pola nomor sequence di
 * seluruh sistem ini.
 */
class NumberAllocator
{
    public function allocate(string $prefix): string
    {
        $key = $prefix . '-' . now()->format('Y');

        $row = DB::selectOne(
            'INSERT INTO quality.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = quality.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
