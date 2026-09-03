<?php

namespace App\Modules\Encounter\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingReferral extends Model
{
    public const STATUS_AKTIF = 'aktif';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'encounter.outgoing_referrals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['referred_at' => 'datetime'];
    }
}
