<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\IncidentReport;

/**
 * Alur pelaporan IKP: dilaporkan -> ditinjau -> ditutup. Sengaja berurutan
 * ketat — insiden tidak bisa ditutup tanpa lebih dulu ditinjau, supaya akar
 * masalah dan tindakan korektif selalu ada sebelum kasus dianggap selesai.
 */
class IncidentReportService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function report(array $data, int $reportedBy): IncidentReport
    {
        return IncidentReport::query()->create($data + [
            'report_number' => $this->numbers->allocate('IKP'),
            'status' => IncidentReport::STATUS_DILAPORKAN,
            'reported_by' => $reportedBy,
        ]);
    }

    public function review(IncidentReport $report, int $reviewedBy): IncidentReport
    {
        if ($report->status !== IncidentReport::STATUS_DILAPORKAN) {
            throw new QualityException('Insiden ini sudah ditinjau atau ditutup.');
        }

        $report->update([
            'status' => IncidentReport::STATUS_DITINJAU,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ]);

        return $report->refresh();
    }

    public function close(IncidentReport $report, string $rootCause, string $correctiveAction): IncidentReport
    {
        if ($report->status !== IncidentReport::STATUS_DITINJAU) {
            throw new QualityException('Insiden harus ditinjau lebih dulu sebelum bisa ditutup.');
        }

        $report->update([
            'status' => IncidentReport::STATUS_DITUTUP,
            'root_cause' => $rootCause,
            'corrective_action' => $correctiveAction,
            'closed_at' => now(),
        ]);

        return $report->refresh();
    }
}
