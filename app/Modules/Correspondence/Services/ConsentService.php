<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\PatientConsent;

class ConsentService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function issue(array $data, int $issuedBy): PatientConsent
    {
        return PatientConsent::query()->create($data + [
            'consent_number' => $this->numbers->allocate('PST'),
            'issued_by' => $issuedBy,
            'signed_at' => now(),
            'status' => PatientConsent::STATUS_AKTIF,
        ]);
    }

    public function cancel(PatientConsent $consent): PatientConsent
    {
        if ($consent->status !== PatientConsent::STATUS_AKTIF) {
            throw new CorrespondenceException('Persetujuan ini sudah dibatalkan.');
        }

        $consent->update(['status' => PatientConsent::STATUS_DIBATALKAN]);

        return $consent->refresh();
    }
}
