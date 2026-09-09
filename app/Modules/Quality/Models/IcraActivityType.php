<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipe aktivitas proyek ICRA (A-D) — dari pedoman, bukan diskresi RS. */
class IcraActivityType extends Model
{
    protected $table = 'quality.icra_activity_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
