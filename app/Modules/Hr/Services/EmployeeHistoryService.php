<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeEducation;
use App\Modules\Hr\Models\EmployeePositionHistory;
use App\Modules\Hr\Models\EmployeeRecord;
use App\Modules\Hr\Models\EmployeeSalaryHistory;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat jabatan, gaji, pendidikan, dan catatan (penghargaan/peringatan)
 * seorang pegawai. Terpisah dari EmployeeService (yang menangani data induk
 * employees & jenis cuti) supaya masing-masing tetap fokus pada satu
 * kelompok tabel.
 */
class EmployeeHistoryService
{
    /**
     * Mencatat perubahan jabatan: menutup baris riwayat yang masih terbuka
     * (end_date null) tepat sehari sebelum tanggal efektif yang baru, lalu
     * memperbarui snapshot jabatan pada baris employees supaya keduanya
     * tidak pernah berbeda.
     */
    public function recordPositionChange(Employee $employee, array $data): EmployeePositionHistory
    {
        return DB::transaction(function () use ($employee, $data) {
            $terbuka = $employee->positionHistory()->whereNull('end_date')->first();

            if ($terbuka !== null) {
                $terbuka->update(['end_date' => date('Y-m-d', strtotime($data['effective_date'] . ' -1 day'))]);
            }

            $riwayat = $employee->positionHistory()->create([
                'position' => $data['position'],
                'unit_id' => $data['unit_id'] ?? null,
                'effective_date' => $data['effective_date'],
                'sk_number' => $data['sk_number'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $employee->update([
                'position' => $data['position'],
                'unit_id' => $data['unit_id'] ?? $employee->unit_id,
            ]);

            return $riwayat;
        });
    }

    public function recordSalaryChange(Employee $employee, array $data): EmployeeSalaryHistory
    {
        return $employee->salaryHistory()->create($data);
    }

    public function addEducation(Employee $employee, array $data): EmployeeEducation
    {
        return $employee->educations()->create($data);
    }

    public function updateEducation(EmployeeEducation $education, array $data): EmployeeEducation
    {
        $education->update($data);

        return $education->refresh();
    }

    public function addRecord(Employee $employee, array $data): EmployeeRecord
    {
        return $employee->records()->create($data);
    }

    public function updateRecord(EmployeeRecord $record, array $data): EmployeeRecord
    {
        $record->update($data);

        return $record->refresh();
    }
}
