<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kelompok risiko pasien area terdampak (1-4) — dari pedoman. */
class IcraRiskGroup extends Model
{
    protected $table = 'quality.icra_risk_groups';

    protected $guarded = ['id'];

    public function areas(): HasMany
    {
        return $this->hasMany(IcraArea::class, 'risk_group_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
