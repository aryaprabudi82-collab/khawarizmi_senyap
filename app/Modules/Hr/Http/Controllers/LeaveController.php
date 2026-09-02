<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveController
{
    public function __construct(private readonly LeaveService $leave) {}

    public function index(): View
    {
        return view('hr::cuti.index', [
            'pengajuan' => LeaveRequest::query()->with(['employee', 'leaveType'])->latest('created_at')->limit(50)->get(),
            'pegawai' => Employee::query()->where('is_active', true)->orderBy('name')->get(),
            'jenisCuti' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'employee_id' => 'pegawai', 'leave_type_id' => 'jenis cuti', 'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai',
        ]);

        try {
            $this->leave->request(
                employeeId: $data['employee_id'],
                leaveTypeId: $data['leave_type_id'],
                startDate: $data['start_date'],
                endDate: $data['end_date'],
                reason: $data['reason'] ?? null,
                requestedBy: $request->user()->id,
            );
        } catch (HrException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan cuti tersimpan.');
    }

    public function approve(Request $request, LeaveRequest $pengajuan): RedirectResponse
    {
        try {
            $this->leave->approve($pengajuan, $request->user()->id);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan cuti disetujui.');
    }

    public function reject(Request $request, LeaveRequest $pengajuan): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']], [], ['rejection_reason' => 'alasan penolakan']);

        try {
            $this->leave->reject($pengajuan, $request->user()->id, $data['rejection_reason']);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan cuti ditolak.');
    }

    public function cancel(LeaveRequest $pengajuan): RedirectResponse
    {
        try {
            $this->leave->cancel($pengajuan);
        } catch (HrException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan cuti dibatalkan.');
    }
}
