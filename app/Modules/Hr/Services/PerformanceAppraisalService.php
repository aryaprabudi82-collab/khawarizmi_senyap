<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PerformanceAppraisal;

/** SKP (Sasaran Kinerja Pegawai) — lihat catatan migrasi untuk alasan struktur draft/final. */
class PerformanceAppraisalService
{
    public function record(Employee $employee, array $data, int $assessorId): PerformanceAppraisal
    {
        $existing = $employee->appraisals()->where('period', $data['period'])->first();

        if ($existing !== null && $existing->status === PerformanceAppraisal::STATUS_FINAL) {
            throw new HrException("Penilaian SKP periode {$data['period']} sudah final, tidak bisa diubah lagi.");
        }

        return $employee->appraisals()->updateOrCreate(
            ['period' => $data['period']],
            [
                'score' => $data['score'],
                'note' => $data['note'] ?? null,
                'assessed_by' => $assessorId,
            ]
        );
    }

    public function finalize(PerformanceAppraisal $appraisal): PerformanceAppraisal
    {
        if ($appraisal->status === PerformanceAppraisal::STATUS_FINAL) {
            throw new HrException('Penilaian SKP ini sudah final, tidak bisa difinalisasi ulang.');
        }

        $appraisal->update(['status' => PerformanceAppraisal::STATUS_FINAL, 'finalized_at' => now()]);

        return $appraisal->refresh();
    }
}
