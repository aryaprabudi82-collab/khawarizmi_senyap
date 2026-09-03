<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('hr::pegawai.index', [
            'pegawai' => Employee::query()->orderBy('name')->get(),
            'jenisCuti' => LeaveType::query()->orderBy('name')->get(),
            'unit' => $this->organization->units(),
            'praktisi' => $this->organization->practitioners(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:20', Rule::unique(Employee::class, 'employee_number')],
            'name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:100'],
            'employment_type' => ['required', 'in:tetap,kontrak,honorer,magang'],
            'unit_id' => ['nullable', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'hire_date' => ['required', 'date'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
        ], [], [
            'employee_number' => 'NIP/NIK', 'name' => 'nama', 'position' => 'jabatan', 'employment_type' => 'status kepegawaian',
            'unit_id' => 'unit', 'hire_date' => 'tanggal masuk',
        ]);

        $this->employees->createEmployee($data + ['is_active' => true]);

        return back()->with('sukses', "Pegawai {$data['name']} ditambahkan.");
    }

    /**
     * Koreksi data cepat (typo nama, ganti nomor telepon, dst.) — sengaja
     * TIDAK mencatat riwayat jabatan meski field position ikut disunting di
     * sini. Perubahan jabatan yang sesungguhnya (mutasi/promosi dengan SK)
     * harus lewat form "Riwayat Jabatan" di halaman detail pegawai, supaya
     * riwayatnya tercatat dengan tanggal efektif dan nomor SK yang benar.
     */
    public function update(Request $request, Employee $pegawai): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:100'],
            'employment_type' => ['required', 'in:tetap,kontrak,honorer,magang'],
            'unit_id' => ['nullable', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'termination_date' => ['nullable', 'date'],
        ], [], ['name' => 'nama', 'position' => 'jabatan', 'employment_type' => 'status kepegawaian']);

        $data['is_active'] = $request->boolean('is_active');

        $this->employees->updateEmployee($pegawai, $data);

        return back()->with('sukses', "Pegawai {$pegawai->name} diperbarui.");
    }

    public function storeLeaveType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(LeaveType::class, 'code')],
            'name' => ['required', 'string', 'max:100'],
            'annual_quota' => ['nullable', 'integer', 'min:1'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'annual_quota' => 'jatah tahunan']);

        $this->employees->createLeaveType($data + ['is_active' => true]);

        return back()->with('sukses', "Jenis cuti {$data['name']} ditambahkan.");
    }

    public function updateLeaveType(Request $request, LeaveType $jenisCuti): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'annual_quota' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'annual_quota' => 'jatah tahunan']);

        $data['is_active'] = $request->boolean('is_active');

        $this->employees->updateLeaveType($jenisCuti, $data);

        return back()->with('sukses', "Jenis cuti {$jenisCuti->name} diperbarui.");
    }
}
