<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca rujukan keluar milik konteks encounter, lewat kontrak yang
 * diterbitkannya.
 *
 * Dipakai Sisrute (domain L item P) untuk MENGIRIM rujukan yang sudah
 * tercatat, bukan untuk menyalinnya. Rujukannya sendiri tetap milik
 * encounter; integration cuma mencatat pengiriman dan jawabannya.
 */
class ReferralContext
{
    private const VIEW = 'encounter.v_outgoing_referral';

    public function outgoing(int $referralId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $referralId)->first();
    }

    /**
     * Rujukan keluar yang belum pernah diajukan ke Sisrute.
     *
     * Inilah daftar yang menunjukkan pasien mana yang sudah dinyatakan
     * perlu dirujuk tapi belum dicarikan tempat.
     */
    public function notYetSent(int $limit = 100): Collection
    {
        return DB::table(self::VIEW . ' as r')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('integration.sisrute_referrals as s')
                    ->whereColumn('s.outgoing_referral_id', 'r.id')
                    ->whereIn('s.status', ['diajukan', 'diterima']);
            })
            ->orderByDesc('r.referred_at')
            ->limit($limit)
            ->get();
    }
}
