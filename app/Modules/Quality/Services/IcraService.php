<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\IcraAssessment;

class IcraService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function assess(array $data, int $assessedBy): IcraAssessment
    {
        return IcraAssessment::query()->create($data + [
            'assessment_number' => $this->numbers->allocate('ICRA'),
            'status' => IcraAssessment::STATUS_AKTIF,
            'assessed_by' => $assessedBy,
            'assessed_at' => now(),
        ]);
    }

    public function complete(IcraAssessment $assessment): IcraAssessment
    {
        if ($assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan.');
        }

        $assessment->update(['status' => IcraAssessment::STATUS_SELESAI]);

        return $assessment->refresh();
    }

    public function cancel(IcraAssessment $assessment): IcraAssessment
    {
        if ($assessment->status !== IcraAssessment::STATUS_AKTIF) {
            throw new QualityException('Kajian ini sudah selesai atau dibatalkan.');
        }

        $assessment->update(['status' => IcraAssessment::STATUS_DIBATALKAN]);

        return $assessment->refresh();
    }
}
