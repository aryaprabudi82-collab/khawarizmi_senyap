<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Encounter\Models\IgdTriage;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Organization\Services\OrganizationDirectory;
use App\Modules\Platform\Models\User;
use Carbon\CarbonImmutable;

/** igd — lihat catatan migrasi encounter untuk kenapa registrasinya memakai jalur RegistrationService biasa. */
class IgdService
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly OrganizationDirectory $organization,
    ) {}

    /** @throws RegistrationException */
    public function register(int $patientId, int $payerId, array $extra = [], ?int $actorId = null): Registration
    {
        $igd = $this->organization->findUnitByCode('IGD')
            ?? throw new RegistrationException('Unit IGD belum terdaftar di Data Master.');

        return $this->registrations->register(
            patientId: $patientId,
            unitId: $igd->id,
            payerId: $payerId,
            serviceDate: CarbonImmutable::now(),
            extra: array_merge($extra, ['care_type' => 'igd']),
            actorId: $actorId,
        );
    }

    /** Mencatat atau memperbarui triase — retriase menimpa baris yang sama, lihat catatan migrasi. */
    public function triage(Registration $registration, string $level, string $complaint, User $actor): IgdTriage
    {
        return IgdTriage::query()->updateOrCreate(
            ['registration_id' => $registration->id],
            [
                'triage_level' => $level,
                'chief_complaint' => $complaint,
                'triaged_by' => $actor->id,
                'triaged_by_name' => $actor->name,
                'triaged_at' => now(),
            ]
        );
    }
}
