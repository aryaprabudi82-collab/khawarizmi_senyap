<?php

namespace App\Modules\Inpatient\Services;

use Illuminate\Support\Facades\DB;

/** Penomoran atomik — lihat catatan yang sama di Quality\Services\NumberAllocator. */
class NumberAllocator
{
    public function allocate(string $prefix): string
    {
        $key = $prefix . '-' . now()->format('Y');

        $row = DB::selectOne(
            'INSERT INTO inpatient.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = inpatient.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
