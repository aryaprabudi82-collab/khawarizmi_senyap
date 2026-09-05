<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pembacaan rinci milik konteks billing untuk keperluan akuntansi (domain
 * I item E), lewat dua kontrak terbitan: v_payment_detail dan
 * v_charge_detail.
 *
 * Kedua view sudah menyaring pembayaran yang dibatalkan dan tagihan yang
 * di-void, jadi aturan itu tidak perlu diulang di sini — cukup satu
 * tempat yang memegangnya, yaitu konteks yang memiliki datanya.
 */
class BillingDetailContext
{
    /** Uang masuk dikelompokkan per cara bayar — dasar pembayaran_akun_bayar. */
    public function paymentsByMethod(string $from, string $until): Collection
    {
        return DB::table('billing.v_payment_detail')
            ->whereBetween(DB::raw('paid_at::date'), [$from, $until])
            ->groupBy('method')
            ->selectRaw('method as key, count(*) as jumlah, sum(amount) as total')
            ->orderBy('method')
            ->get();
    }

    /** Pendapatan dikelompokkan per jenis biaya — dasar pendapatan_per_akun. */
    public function chargesBySource(string $from, string $until): Collection
    {
        return DB::table('billing.v_charge_detail')
            ->whereBetween(DB::raw('charged_at::date'), [$from, $until])
            ->groupBy('source_type')
            ->selectRaw('source_type as key, count(*) as jumlah, sum(amount) as total')
            ->orderBy('source_type')
            ->get();
    }
}
