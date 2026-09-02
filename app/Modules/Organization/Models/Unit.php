<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $table = 'organization.units';

    protected $fillable = ['code', 'name', 'kind', 'parent_id', 'is_active', 'daily_quota'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'daily_quota' => 'integer'];
    }
}
