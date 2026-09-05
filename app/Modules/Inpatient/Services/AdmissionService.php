<?php

namespace App\Modules\Inpatient\Services;

use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\DietOrder;
use App\Modules\Inpatient\Models\DpjpHistory;
use Illuminate\Support\Facades\DB;

class AdmissionService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly EncounterContext $encounter,
        private readonly OrganizationContext $organization,
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

            $admisi = Admission::query()->create([
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

            // Baris penempatan bed pertama. Riwayat ini yang membuat biaya
            // kamar per hari tetap benar kalau pasien pindah kelas nanti.
            $admisi->bedAssignments()->create([
                'bed_id' => $bed->id,
                'assigned_at' => now(),
                'changed_by' => $actorId,
            ]);

            // Baris dpjp_history pertama, kalau registrasinya sudah punya
            // dokter penanggung jawab — booking tanpa memilih dokter tetap
            // sah diadmisi, DPJP-nya menyusul lewat reassignDpjp().
            if ($registrasi->practitioner_id !== null) {
                $admisi->dpjpHistory()->create([
                    'practitioner_id' => $registrasi->practitioner_id,
                    'practitioner_name' => $registrasi->practitioner_name,
                    'start_at' => now(),
                ]);
            }

            return $admisi;
        });
    }

    /**
     * dpjp_ranap — mengganti DPJP di tengah rawatan (alih rawat/konsul).
     * Menutup baris dpjp_history yang masih terbuka, mencatat yang baru, dan
     * menyamakan snapshot admissions.dpjp_name — pola sama dengan
     * EmployeeHistoryService::recordPositionChange.
     *
     * @throws InpatientException
     */
    public function reassignDpjp(Admission $admission, int $practitionerId, ?string $reason, ?int $actorId = null): Admission
    {
        if ($admission->status !== Admission::STATUS_DIRAWAT) {
            throw new InpatientException("Admisi {$admission->admission_number} sudah tidak dirawat, DPJP tidak bisa diganti.");
        }

        $praktisi = $this->organization->findPractitioner($practitionerId)
            ?? throw new InpatientException('Dokter tidak ditemukan.');

        if (! $praktisi->is_active) {
            throw new InpatientException("{$praktisi->name} sedang tidak aktif.");
        }

        if ($admission->dpjp_practitioner_id === $practitionerId) {
            throw new InpatientException("{$praktisi->name} sudah menjadi DPJP admisi ini.");
        }

        return DB::transaction(function () use ($admission, $praktisi, $reason, $actorId) {
            $admission->dpjpHistory()->whereNull('end_at')->update(['end_at' => now()]);

            $admission->dpjpHistory()->create([
                'practitioner_id' => $praktisi->id,
                'practitioner_name' => $praktisi->name,
                'start_at' => now(),
                'reason' => $reason,
                'changed_by' => $actorId,
            ]);

            $admission->update([
                'dpjp_practitioner_id' => $praktisi->id,
                'dpjp_name' => $praktisi->name,
            ]);

            return $admission->refresh();
        });
    }

    /**
     * Memindahkan pasien ke bed lain di tengah rawatan — naik/turun kelas,
     * butuh isolasi, atau sekadar rotasi ruang.
     *
     * Menutup penempatan yang masih terbuka lalu membuka yang baru, pola
     * yang sama dengan reassignDpjp(). Ini yang membuat biaya kamar per
     * hari tetap benar: hari-hari sebelum pindah tetap ditagih dengan tarif
     * kamar lama, bukan ikut berubah surut mengikuti kamar baru.
     *
     * @throws InpatientException
     */
    public function transferBed(Admission $admission, Bed $bed, ?string $reason = null, ?int $actorId = null): Admission
    {
        if ($admission->status !== Admission::STATUS_DIRAWAT) {
            throw new InpatientException("Admisi {$admission->admission_number} sudah tidak dirawat, bed tidak bisa dipindah.");
        }

        if ($admission->bed_id === $bed->id) {
            throw new InpatientException("Pasien sudah menempati bed {$bed->bed_number}.");
        }

        if ($bed->status !== Bed::STATUS_TERSEDIA) {
            throw new InpatientException("Bed {$bed->bed_number} berstatus '{$bed->status}', tidak tersedia untuk diisi.");
        }

        return DB::transaction(function () use ($admission, $bed, $reason, $actorId) {
            // Bed lama dilepas ke status dibersihkan, sama seperti saat pulang.
            $admission->bed->update(['status' => Bed::STATUS_DIBERSIHKAN]);
            $bed->update(['status' => Bed::STATUS_TERISI]);

            $admission->bedAssignments()->whereNull('released_at')->update(['released_at' => now()]);

            $admission->bedAssignments()->create([
                'bed_id' => $bed->id,
                'assigned_at' => now(),
                'reason' => $reason,
                'changed_by' => $actorId,
            ]);

            // bed_id tetap dipakai sebagai cache bed terkini, seperti dpjp_name.
            $admission->update(['bed_id' => $bed->id]);

            return $admission->refresh();
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

            // Order diet berhenti otomatis bersama kepulangan — tidak ada
            // gunanya diet order tetap "aktif" untuk pasien yang sudah pulang.
            $admission->activeDietOrder?->update([
                'status' => DietOrder::STATUS_DIHENTIKAN,
                'end_date' => now()->toDateString(),
            ]);

            $admission->bedAssignments()->whereNull('released_at')->update(['released_at' => now()]);

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
