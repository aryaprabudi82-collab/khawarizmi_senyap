<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Encounter\Models\KfrProgramRequest;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** layanan_program_kfr — lihat catatan migrasi encounter untuk kenapa terpisah dari registrasi biasa. */
class KfrProgramRequestService
{
    public function issue(Registration $registration, array $data, User $actor): KfrProgramRequest
    {
        return KfrProgramRequest::query()->create($data + [
            'request_number' => $this->allocateNumber(),
            'registration_id' => $registration->id,
            'patient_id' => $registration->patient_id,
            'patient_mrn' => $registration->patient_mrn,
            'patient_name' => $registration->patient_name,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_at' => now(),
            'status' => KfrProgramRequest::STATUS_DIMINTA,
        ]);
    }

    /** @throws RegistrationException */
    public function cancel(KfrProgramRequest $request): KfrProgramRequest
    {
        if ($request->status !== KfrProgramRequest::STATUS_DIMINTA) {
            throw new RegistrationException('Permintaan ini sudah dibatalkan.');
        }

        $request->update(['status' => KfrProgramRequest::STATUS_DIBATALKAN]);

        return $request->refresh();
    }

    private function allocateNumber(): string
    {
        $prefix = 'KFR-' . now()->format('Y');

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
