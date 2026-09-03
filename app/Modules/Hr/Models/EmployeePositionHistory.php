<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePositionHistory extends Model
{
    protected $table = 'hr.employee_position_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'end_date' => 'date'];
    }
}
