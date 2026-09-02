<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendanceRecord;
use Carbon\CarbonImmutable;

class AttendanceService
{
    public function checkIn(int $employeeId, ?int $recordedBy = null): AttendanceRecord
    {
        $tanggal = CarbonImmutable::now()->toDateString();

        $existing = AttendanceRecord::query()
            ->where('employee_id', $employeeId)->where('attendance_date', $tanggal)->first();

        if ($existing !== null && $existing->check_in_at !== null) {
            throw new HrException('Sudah presensi masuk hari ini.');
        }

        if ($existing !== null) {
            $existing->update(['check_in_at' => now(), 'status' => AttendanceRecord::STATUS_HADIR, 'recorded_by' => $recordedBy]);

            return $existing->refresh();
        }

        return AttendanceRecord::query()->create([
            'employee_id' => $employeeId,
            'attendance_date' => $tanggal,
            'check_in_at' => now(),
            'status' => AttendanceRecord::STATUS_HADIR,
            'recorded_by' => $recordedBy,
        ]);
    }

    public function checkOut(int $employeeId): AttendanceRecord
    {
        $tanggal = CarbonImmutable::now()->toDateString();

        $record = AttendanceRecord::query()
            ->where('employee_id', $employeeId)->where('attendance_date', $tanggal)->first();

        if ($record === null || $record->check_in_at === null) {
            throw new HrException('Belum presensi masuk hari ini.');
        }

        if ($record->check_out_at !== null) {
            throw new HrException('Sudah presensi pulang hari ini.');
        }

        $record->update(['check_out_at' => now()]);

        return $record->refresh();
    }

    /** Entri manual oleh petugas HR — untuk izin/sakit/alpha/cuti yang tidak melalui checkin/checkout. */
    public function recordManual(int $employeeId, string $date, string $status, ?int $recordedBy, ?string $note = null): AttendanceRecord
    {
        return AttendanceRecord::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'attendance_date' => $date],
            ['status' => $status, 'recorded_by' => $recordedBy, 'note' => $note],
        );
    }
}
