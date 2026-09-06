<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu hasil pencarian rujukan di VClaim.
 *
 * Isinya SALINAN JAWABAN BPJS, bukan kebenaran kita sendiri — tidak pernah
 * dipakai menggantikan data pasien di konteks identity.
 */
class BpjsReferralLookup extends Model
{
    protected $table = 'integration.bpjs_referral_lookups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['found' => 'boolean', 'referral_date' => 'date', 'raw_response' => 'array'];
    }

    /**
     * Rujukan BPJS berlaku 90 hari. Kedaluwarsa dilaporkan di layar, bukan
     * diam-diam diloloskan lalu ditolak saat SEP diterbitkan.
     */
    public function isExpired(): bool
    {
        if (! $this->found || $this->referral_date === null) {
            return false;
        }

        return $this->referral_date->diffInDays(now()) > \App\Modules\Integration\Services\Bpjs\ReferralService::MASA_BERLAKU_HARI;
    }

    public function daysOld(): ?int
    {
        return $this->referral_date?->diffInDays(now());
    }
}
