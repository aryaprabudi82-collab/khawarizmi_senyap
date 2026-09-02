<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    public $timestamps = false;

    protected $table = 'hr.leave_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
