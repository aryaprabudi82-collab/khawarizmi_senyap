<?php

namespace App\Modules\Blood\Services;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Models\TransfusionIssue;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

class TransfusionService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function issue(
        BloodUnit $unit,
        string $patientName,
        User $actor,
        ?int $patientId = null,
        ?int $registrationId = null,
        ?string $indication = null,
    ): TransfusionIssue {
        if ($unit->status !== BloodUnit::STATUS_TERSEDIA) {
            throw new BloodException("Unit darah {$unit->unit_number} berstatus '{$unit->status}', tidak bisa dikeluarkan.");
        }

        if ($unit->isExpired()) {
            throw new BloodException("Unit darah {$unit->unit_number} sudah kedaluwarsa sejak {$unit->expiry_date->toDateString()}, tidak boleh ditransfusikan.");
        }

        return DB::transaction(function () use ($unit, $patientName, $actor, $patientId, $registrationId, $indication): TransfusionIssue {
            DB::table('blood.unit_status_logs')->insert([
                'blood_unit_id' => $unit->id,
                'from_status' => $unit->status,
                'to_status' => BloodUnit::STATUS_DIKELUARKAN,
                'reason' => 'Dikeluarkan untuk transfusi.',
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            $unit->update(['status' => BloodUnit::STATUS_DIKELUARKAN]);

            return TransfusionIssue::query()->create([
                'issue_number' => $this->numbers->allocate('TRF'),
                'blood_unit_id' => $unit->id,
                'patient_id' => $patientId,
                'registration_id' => $registrationId,
                'patient_name' => $patientName,
                'indication' => $indication,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);
        });
    }
}
