<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeRecord extends Model
{
    public const TYPE_PENGHARGAAN = 'penghargaan';
    public const TYPE_PERINGATAN = 'peringatan';

    protected $table = 'hr.employee_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['record_date' => 'date'];
    }
}
