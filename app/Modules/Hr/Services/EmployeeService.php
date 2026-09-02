<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveType;

class EmployeeService
{
    public function createEmployee(array $data): Employee
    {
        return Employee::query()->create($data);
    }

    public function updateEmployee(Employee $employee, array $data): Employee
    {
        $employee->update($data);

        return $employee->refresh();
    }

    public function createLeaveType(array $data): LeaveType
    {
        return LeaveType::query()->create($data);
    }

    public function updateLeaveType(LeaveType $leaveType, array $data): LeaveType
    {
        $leaveType->update($data);

        return $leaveType->refresh();
    }
}
