<?php

namespace App\Modules\Library\Services;

use Illuminate\Support\Facades\DB;

/** Penomoran atomik — pola yang sama dengan Correspondence\Services\NumberAllocator. */
class LibraryNumberAllocator
{
    public function allocate(string $prefix): string
    {
        $key = $prefix.'-'.now()->format('Y');

        $row = DB::selectOne(
            'INSERT INTO library.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = library.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key.'-'.str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
