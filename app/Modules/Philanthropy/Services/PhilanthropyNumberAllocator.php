<?php

namespace App\Modules\Philanthropy\Services;

use Illuminate\Support\Facades\DB;

/** Penomoran atomik — pola yang sama dengan konteks correspondence, library, dan retail. */
class PhilanthropyNumberAllocator
{
    public function allocate(string $prefix): string
    {
        $key = $prefix.'-'.now()->format('Y');

        $row = DB::selectOne(
            'INSERT INTO philanthropy.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = philanthropy.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key.'-'.str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
