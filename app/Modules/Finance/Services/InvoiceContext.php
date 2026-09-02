<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat konteks finance menyentuh data milik konteks billing.
 *
 * Dibaca lewat billing.v_settled_invoice — kontrak yang diterbitkan konteks
 * billing, berisi tagihan yang sudah lunas atau ditanggung penjamin.
 */
class InvoiceContext
{
    private const VIEW = 'billing.v_settled_invoice';

    /** Tagihan yang sudah tuntas tapi belum diposting ke jurnal. */
    public function unpostedSettled(): Collection
    {
        return DB::table(self::VIEW . ' as i')
            ->leftJoin('finance.journal_entries as j', function ($join) {
                $join->on('j.reference_id', '=', 'i.invoice_id')
                    ->where('j.reference_type', '=', 'invoice');
            })
            ->whereNull('j.id')
            ->select('i.*')
            ->orderBy('i.closed_at')
            ->get();
    }
}
