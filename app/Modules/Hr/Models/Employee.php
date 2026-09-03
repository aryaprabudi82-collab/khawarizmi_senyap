<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $table = 'hr.employees';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function positionHistory(): HasMany
    {
        return $this->hasMany(EmployeePositionHistory::class)->orderByDesc('effective_date');
    }

    public function salaryHistory(): HasMany
    {
        return $this->hasMany(EmployeeSalaryHistory::class)->orderByDesc('effective_date');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class)->orderByDesc('graduation_year');
    }

    public function records(): HasMany
    {
        return $this->hasMany(EmployeeRecord::class)->orderByDesc('record_date');
    }

    public function appraisals(): HasMany
    {
        return $this->hasMany(PerformanceAppraisal::class)->orderByDesc('period');
    }
}
