<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveType;

class EmployeeService
{
    public function __construct(private readonly EmployeeHistoryService $history) {}

    public function createEmployee(array $data): Employee
    {
        $employee = Employee::query()->create($data);

        // Baris pertama riwayat jabatan supaya profil pegawai tidak pernah
        // tampak "belum ada riwayat" padahal jelas dia punya jabatan sejak masuk.
        $this->history->recordPositionChange($employee, [
            'position' => $data['position'],
            'unit_id' => $data['unit_id'] ?? null,
            'effective_date' => $data['hire_date'],
            'note' => 'Jabatan awal saat masuk kerja.',
        ]);

        return $employee->fresh();
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
