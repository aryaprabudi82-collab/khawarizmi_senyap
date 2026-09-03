<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only — lihat catatan migrasi. Tidak ada method update di service. */
class EmployeeSalaryHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr.employee_salary_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'base_salary' => 'decimal:2'];
    }
}
