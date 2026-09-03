<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    public const CLASSES = ['vip', 'kelas-1', 'kelas-2', 'kelas-3', 'icu', 'isolasi'];

    protected $table = 'inpatient.rooms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['daily_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }
}
