<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Encounter\Models\OutgoingReferral;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** rujukan_keluar — lihat catatan migrasi encounter untuk alasan bentuknya beda dari rujukan_masuk. */
class ReferralService
{
    public function issue(Registration $registration, array $data, User $actor): OutgoingReferral
    {
        return OutgoingReferral::query()->create($data + [
            'referral_number' => $this->allocateNumber(),
            'registration_id' => $registration->id,
            'patient_id' => $registration->patient_id,
            'patient_mrn' => $registration->patient_mrn,
            'patient_name' => $registration->patient_name,
            'practitioner_id' => $registration->practitioner_id,
            'practitioner_name' => $registration->practitioner_name,
            'referred_at' => now(),
            'status' => OutgoingReferral::STATUS_AKTIF,
            'created_by' => $actor->id,
        ]);
    }

    /** @throws RegistrationException */
    public function cancel(OutgoingReferral $referral): OutgoingReferral
    {
        if ($referral->status !== OutgoingReferral::STATUS_AKTIF) {
            throw new RegistrationException('Rujukan ini sudah dibatalkan.');
        }

        $referral->update(['status' => OutgoingReferral::STATUS_DIBATALKAN]);

        return $referral->refresh();
    }

    private function allocateNumber(): string
    {
        $prefix = 'RJK-' . now()->format('Y');

        $row = DB::selectOne(
            'INSERT INTO encounter.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = encounter.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return $prefix . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
