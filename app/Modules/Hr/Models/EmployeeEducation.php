<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEducation extends Model
{
    public const LEVELS = ['sd', 'smp', 'sma', 'd3', 'd4', 's1', 'profesi', 's2', 'spesialis', 's3'];

    protected $table = 'hr.employee_educations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['graduation_year' => 'integer'];
    }
}
