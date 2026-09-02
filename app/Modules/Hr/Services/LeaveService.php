<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use Carbon\CarbonImmutable;

/**
 * Alur pengajuan cuti: diajukan -> disetujui/ditolak, atau dibatalkan
 * selama masih diajukan. Jatah tahunan (annual_quota) ditegakkan terhadap
 * cuti yang sudah DISETUJUI di tahun kalender yang sama, bukan yang masih
 * diajukan — supaya beberapa pengajuan yang tumpang tindih tetap bisa
 * diajukan bersamaan, dan penolakan salah satunya tidak perlu membatalkan
 * yang lain secara manual.
 */
class LeaveService
{
    public function request(
        int $employeeId,
        int $leaveTypeId,
        string $startDate,
        string $endDate,
        ?string $reason,
        int $requestedBy,
    ): LeaveRequest {
        $mulai = CarbonImmutable::parse($startDate);
        $selesai = CarbonImmutable::parse($endDate);

        if ($selesai->lt($mulai)) {
            throw new HrException('Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        $jenis = LeaveType::query()->findOrFail($leaveTypeId);
        // Carbon 3 mengembalikan float dari diffInDays() — dibulatkan ke int
        // karena days_count adalah kolom smallint, bukan pecahan hari.
        $jumlahHari = (int) $mulai->diffInDays($selesai) + 1;

        if ($jenis->annual_quota !== null) {
            $terpakai = LeaveRequest::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('status', LeaveRequest::STATUS_DISETUJUI)
                ->whereYear('start_date', $mulai->year)
                ->sum('days_count');

            if ($terpakai + $jumlahHari > $jenis->annual_quota) {
                $sisa = max(0, $jenis->annual_quota - $terpakai);

                throw new HrException(
                    "Jatah {$jenis->name} tahun {$mulai->year} tersisa {$sisa} hari, pengajuan ini {$jumlahHari} hari."
                );
            }
        }

        return LeaveRequest::query()->create([
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => $mulai->toDateString(),
            'end_date' => $selesai->toDateString(),
            'days_count' => $jumlahHari,
            'reason' => $reason,
            'status' => LeaveRequest::STATUS_DIAJUKAN,
            'requested_by' => $requestedBy,
        ]);
    }

    public function approve(LeaveRequest $request, int $approvedBy): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new HrException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        $request->update([
            'status' => LeaveRequest::STATUS_DISETUJUI,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        return $request->refresh();
    }

    public function reject(LeaveRequest $request, int $approvedBy, string $reason): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new HrException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        $request->update([
            'status' => LeaveRequest::STATUS_DITOLAK,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->refresh();
    }

    public function cancel(LeaveRequest $request): LeaveRequest
    {
        if (! $request->isPending()) {
            throw new HrException('Hanya pengajuan yang masih diajukan yang bisa dibatalkan.');
        }

        $request->update(['status' => LeaveRequest::STATUS_DIBATALKAN]);

        return $request->refresh();
    }

    /** Sisa jatah cuti tahun berjalan untuk satu jenis cuti bertakwa tahunan. Null kalau jenis cutinya tidak dibatasi. */
    public function remainingQuota(Employee $employee, LeaveType $leaveType, ?int $year = null): ?int
    {
        if ($leaveType->annual_quota === null) {
            return null;
        }

        $year ??= now()->year;

        $terpakai = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('status', LeaveRequest::STATUS_DISETUJUI)
            ->whereYear('start_date', $year)
            ->sum('days_count');

        return max(0, $leaveType->annual_quota - $terpakai);
    }
}
