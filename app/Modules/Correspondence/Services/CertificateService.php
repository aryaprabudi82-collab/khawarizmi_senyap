<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\MedicalCertificate;

class CertificateService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function issue(array $data, int $issuedBy): MedicalCertificate
    {
        return MedicalCertificate::query()->create($data + [
            'certificate_number' => $this->numbers->allocate('SKT'),
            'issued_by' => $issuedBy,
            'issued_at' => now(),
            'status' => MedicalCertificate::STATUS_DITERBITKAN,
        ]);
    }

    public function cancel(MedicalCertificate $certificate): MedicalCertificate
    {
        if ($certificate->status !== MedicalCertificate::STATUS_DITERBITKAN) {
            throw new CorrespondenceException('Surat ini sudah dibatalkan.');
        }

        $certificate->update(['status' => MedicalCertificate::STATUS_DIBATALKAN]);

        return $certificate->refresh();
    }
}
