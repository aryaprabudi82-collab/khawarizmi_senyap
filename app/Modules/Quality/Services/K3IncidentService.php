<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\K3Incident;

/**
 * Alur insiden K3 sama seperti IKP: dilaporkan -> ditinjau -> ditutup,
 * berurutan ketat. Insiden K3 menyangkut pegawai/tenaga kerja sebagai
 * korban (kecelakaan kerja), beda subjek dari IKP yang menyangkut pasien
 * — makanya tabel dan service-nya terpisah meski alurnya identik.
 */
class K3IncidentService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function report(array $data, int $reportedBy): K3Incident
    {
        return K3Incident::query()->create($data + [
            'incident_number' => $this->numbers->allocate('K3'),
            'status' => K3Incident::STATUS_DILAPORKAN,
            'reported_by' => $reportedBy,
        ]);
    }

    public function review(K3Incident $incident, int $reviewedBy): K3Incident
    {
        if ($incident->status !== K3Incident::STATUS_DILAPORKAN) {
            throw new QualityException('Insiden ini sudah ditinjau atau ditutup.');
        }

        $incident->update([
            'status' => K3Incident::STATUS_DITINJAU,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ]);

        return $incident->refresh();
    }

    public function close(K3Incident $incident, string $correctiveAction): K3Incident
    {
        if ($incident->status !== K3Incident::STATUS_DITINJAU) {
            throw new QualityException('Insiden harus ditinjau lebih dulu sebelum bisa ditutup.');
        }

        $incident->update([
            'status' => K3Incident::STATUS_DITUTUP,
            'corrective_action' => $correctiveAction,
            'closed_at' => now(),
        ]);

        return $incident->refresh();
    }
}
