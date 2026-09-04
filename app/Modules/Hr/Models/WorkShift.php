<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class WorkShift extends Model
{
    protected $table = 'hr.work_shifts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
