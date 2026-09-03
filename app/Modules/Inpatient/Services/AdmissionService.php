<?php

namespace App\Modules\Inpatient\Services;

use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use Illuminate\Support\Facades\DB;

class AdmissionService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly EncounterContext $encounter,
    ) {}

    /**
     * @throws InpatientException
     */
    public function admit(int $registrationId, Bed $bed, ?int $actorId = null): Admission
    {
        $registrasi = $this->encounter->find($registrationId)
            ?? throw new InpatientException('Registrasi tidak ditemukan.');

        if ($registrasi->care_type !== 'ranap') {
            throw new InpatientException("Registrasi {$registrasi->registration_number} bukan registrasi rawat inap.");
        }

        if (Admission::query()->where('registration_id', $registrationId)->exists()) {
            throw new InpatientException("Registrasi {$registrasi->registration_number} sudah punya admisi.");
        }

        if ($bed->status !== Bed::STATUS_TERSEDIA) {
            throw new InpatientException("Bed {$bed->bed_number} berstatus '{$bed->status}', tidak tersedia untuk diisi.");
        }

        return DB::transaction(function () use ($registrasi, $bed, $actorId) {
            $bed->update(['status' => Bed::STATUS_TERISI]);

            return Admission::query()->create([
                'admission_number' => $this->numbers->allocate('RANAP'),
                'registration_id' => $registrasi->id,
                'patient_id' => $registrasi->patient_id,
                'patient_mrn' => $registrasi->patient_mrn,
                'patient_name' => $registrasi->patient_name,
                'bed_id' => $bed->id,
                'dpjp_practitioner_id' => $registrasi->practitioner_id,
                'dpjp_name' => $registrasi->practitioner_name,
                'admitted_at' => now(),
                'status' => Admission::STATUS_DIRAWAT,
                'admitted_by' => $actorId,
            ]);
        });
    }

    /**
     * @throws InpatientException
     */
    public function discharge(Admission $admission, string $dischargeStatus, ?string $note, ?int $actorId = null): Admission
    {
        if ($admission->status !== Admission::STATUS_DIRAWAT) {
            throw new InpatientException("Admisi {$admission->admission_number} sudah selesai dirawat.");
        }

        return DB::transaction(function () use ($admission, $dischargeStatus, $note, $actorId) {
            $admission->bed->update(['status' => Bed::STATUS_DIBERSIHKAN]);

            $admission->update([
                'status' => Admission::STATUS_PULANG,
                'discharged_at' => now(),
                'discharge_status' => $dischargeStatus,
                'note' => $note,
                'discharged_by' => $actorId,
            ]);

            return $admission->refresh();
        });
    }
}
