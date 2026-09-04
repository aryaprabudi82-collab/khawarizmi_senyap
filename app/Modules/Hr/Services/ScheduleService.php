<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\DutySchedule;
use App\Modules\Hr\Models\WorkShift;

/** jam_masuk (WorkShift) dan jadwal_pegawai (DutySchedule) — lihat catatan migrasi hr untuk konteksnya. */
class ScheduleService
{
    public function createShift(array $data): WorkShift
    {
        return WorkShift::query()->create($data);
    }

    public function updateShift(WorkShift $shift, array $data): WorkShift
    {
        $shift->update($data);

        return $shift->refresh();
    }

    /** @throws HrException */
    public function assignDuty(int $employeeId, int $workShiftId, string $date, ?int $unitId, ?string $unitName, ?string $note): DutySchedule
    {
        return DutySchedule::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'schedule_date' => $date],
            [
                'work_shift_id' => $workShiftId,
                'unit_id' => $unitId,
                'unit_name' => $unitName,
                'status' => DutySchedule::STATUS_TERJADWAL,
                'note' => $note,
            ]
        );
    }

    /** @throws HrException */
    public function cancelDuty(DutySchedule $jadwal): DutySchedule
    {
        if ($jadwal->status === DutySchedule::STATUS_DIBATALKAN) {
            throw new HrException('Jadwal ini sudah dibatalkan.');
        }

        $jadwal->update(['status' => DutySchedule::STATUS_DIBATALKAN]);

        return $jadwal->refresh();
    }
}
