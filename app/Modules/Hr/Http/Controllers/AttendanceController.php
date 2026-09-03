<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\AttendanceRecord;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();

        $pegawai = Employee::query()->where('is_active', true)->orderBy('name')->get();

        $tercatat = AttendanceRecord::query()
            ->whereDate('attendance_date', $tanggal)
            ->get()
            ->keyBy('employee_id');

        return view('hr::presensi.index', [
            'tanggal' => $tanggal,
            'pegawai' => $pegawai,
            'tercatat' => $tercatat,
        ]);
    }

    public function checkIn(Request $request, Employee $pegawai): RedirectResponse
    {
        try {
            $this->attendance->checkIn($pegawai->id, $request->user()->id);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "{$pegawai->name} presensi masuk.");
    }

    public function checkOut(Employee $pegawai): RedirectResponse
    {
        try {
            $this->attendance->checkOut($pegawai->id);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "{$pegawai->name} presensi pulang.");
    }

    public function monthly(Request $request): View
    {
        $bulan = CarbonImmutable::parse($request->query('bulan', now()->format('Y-m')) . '-01');

        return view('hr::presensi.bulanan', [
            'bulan' => $bulan,
            'rekap' => $this->attendance->monthlyRecap($bulan),
        ]);
    }

    public function storeManual(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'in:izin,sakit,alpha,cuti'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['employee_id' => 'pegawai', 'attendance_date' => 'tanggal', 'status' => 'status']);

        $this->attendance->recordManual(
            $data['employee_id'],
            $data['attendance_date'],
            $data['status'],
            $request->user()->id,
            $data['note'] ?? null,
        );

        return back()->with('sukses', 'Presensi tercatat.');
    }
}
