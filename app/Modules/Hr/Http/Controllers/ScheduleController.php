<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\DutySchedule;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkShift;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\OrganizationContext;
use App\Modules\Hr\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** jam_masuk (WorkShift) dan jadwal_pegawai (DutySchedule) — lihat catatan migrasi hr untuk konteksnya. */
class ScheduleController
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();

        return view('hr::jadwal.index', [
            'shift' => WorkShift::query()->orderBy('start_time')->get(),
            'tanggal' => $tanggal,
            'jadwal' => DutySchedule::query()
                ->with(['employee', 'workShift'])
                ->whereDate('schedule_date', $tanggal->toDateString())
                ->get()
                ->keyBy('employee_id'),
            'pegawai' => Employee::query()->where('is_active', true)->orderBy('name')->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function storeShift(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(WorkShift::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'tolerance_minutes' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'code' => 'kode', 'name' => 'nama shift', 'start_time' => 'jam mulai',
            'end_time' => 'jam selesai', 'tolerance_minutes' => 'toleransi keterlambatan',
        ]);

        $this->schedules->createShift($data + ['is_active' => true, 'tolerance_minutes' => $data['tolerance_minutes'] ?? 15]);

        return back()->with('sukses', "Shift {$data['name']} ditambahkan.");
    }

    public function storeDuty(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'work_shift_id' => ['required', 'integer'],
            'schedule_date' => ['required', 'date'],
            'unit_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'employee_id' => 'pegawai', 'work_shift_id' => 'shift', 'schedule_date' => 'tanggal',
        ]);

        $unitName = null;
        if (! empty($data['unit_id'])) {
            $unitName = $this->organization->units()->firstWhere('id', (int) $data['unit_id'])?->name;
        }

        $this->schedules->assignDuty(
            (int) $data['employee_id'], (int) $data['work_shift_id'], $data['schedule_date'],
            $data['unit_id'] ?? null, $unitName, $data['note'] ?? null,
        );

        return back()->with('sukses', 'Jadwal tersimpan.');
    }

    public function cancelDuty(DutySchedule $jadwal): RedirectResponse
    {
        try {
            $this->schedules->cancelDuty($jadwal);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Jadwal dibatalkan.');
    }
}
