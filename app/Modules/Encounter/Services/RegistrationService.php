<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Catalog\Services\TariffLookup;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Services\OrganizationDirectory;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Inti modul A: mendaftarkan pasien untuk satu kunjungan.
 *
 * Data dari konteks lain diambil lewat service masing-masing — bukan JOIN
 * lintas schema. Yang disalin ke baris registrasi hanya kolom yang benar-benar
 * dibutuhkan layar daftar, supaya papan antrean bisa dirender satu query.
 */
class RegistrationService
{
    public const SERVICE_CODE = 'REG-RALAN';

    public function __construct(
        private readonly PatientRegistry $patients,
        private readonly OrganizationDirectory $organization,
        private readonly TariffLookup $tariffs,
        private readonly QueueNumberAllocator $queue,
    ) {}

    /**
     * @throws RegistrationException
     */
    public function register(
        int $patientId,
        int $unitId,
        int $payerId,
        ?int $practitionerId = null,
        ?DateTimeInterface $serviceDate = null,
        array $extra = [],
        ?int $actorId = null,
    ): Registration {
        $date = CarbonImmutable::parse($serviceDate ?? now())->startOfDay();

        $patient = $this->patients->find($patientId)
            ?? throw new RegistrationException('Pasien tidak ditemukan.');

        $unit = $this->organization->findUnit($unitId)
            ?? throw new RegistrationException('Unit layanan tidak ditemukan.');

        if (! $unit->is_active) {
            throw new RegistrationException("Unit {$unit->name} sedang tidak aktif.");
        }

        $payer = $this->tariffs->findPayer($payerId)
            ?? throw new RegistrationException('Penjamin tidak ditemukan.');

        if (! $payer->is_active) {
            throw new RegistrationException("Penjamin {$payer->name} sedang tidak aktif.");
        }

        $practitioner = null;

        if ($practitionerId !== null) {
            $practitioner = $this->organization->findPractitioner($practitionerId)
                ?? throw new RegistrationException('Dokter tidak ditemukan.');

            // Masa aktif diperiksa terhadap tanggal pelayanan, bukan hari ini.
            if (! $this->organization->practitionerIsServing($practitionerId, $date)) {
                throw new RegistrationException(
                    "{$practitioner->name} tidak aktif melayani pada " . $date->format('d-m-Y') . '.'
                );
            }

            // booking_registrasi/booking_periksa: mendaftar untuk tanggal
            // MENDATANG (bukan kunjungan hari ini) wajib cocok jadwal praktik
            // mingguan sungguhan, bukan cuma masa aktif SIP — supaya tidak
            // bisa memesan slot dokter pada hari dia sebetulnya tidak
            // berpraktik. Pendaftaran hari ini (walk-in) tidak diperiksa di
            // sini — jadwal cuma menahan booking ke depan, bukan mengganti
            // kebijaksanaan loket untuk kunjungan yang sedang berlangsung.
            if ($date->isAfter(CarbonImmutable::now()->startOfDay())) {
                $terjadwal = $this->organization->scheduledOn($practitionerId, $date, $unitId);

                if ($terjadwal->isEmpty()) {
                    throw new RegistrationException(
                        "{$practitioner->name} tidak berpraktik di {$unit->name} pada hari "
                        . $date->translatedFormat('l') . ' (' . $date->format('d-m-Y') . ').'
                    );
                }
            }
        }

        return DB::transaction(function () use (
            $patient, $unit, $payer, $practitioner, $date, $extra, $actorId
        ): Registration {
            $this->guardDoubleRegistration($patient->id, $unit->id, $date);

            $returning = $this->hasPreviousVisit($patient->id, $date);

            $queueNumber = $this->queue->allocate($date, $unit->id, $unit->daily_quota);

            $age = $patient->ageOn($date);

            $fee = $this->tariffs->resolve(
                serviceCode: self::SERVICE_CODE,
                payerId: $payer->id,
                on: $date,
                returningPatient: $returning,
            ) ?? 0.0;

            return Registration::query()->create([
                'registration_number' => $this->allocateRegistrationNumber($date),

                'patient_id' => $patient->id,
                'unit_id' => $unit->id,
                'practitioner_id' => $practitioner?->id,
                'payer_id' => $payer->id,

                'patient_mrn' => $patient->medical_record_number,
                'patient_name' => $patient->name,
                'unit_name' => $unit->name,
                'practitioner_name' => $practitioner?->displayName(),
                'payer_name' => $payer->name,

                'service_date' => $date->toDateString(),
                'registered_at' => now(),
                'queue_number' => $queueNumber,

                'visit_type' => $returning ? 'lama' : 'baru',
                'care_type' => $extra['care_type'] ?? 'ralan',
                'status' => Registration::STATUS_TERDAFTAR,

                'age_years' => $age['years'],
                'age_months' => $age['months'],
                'age_days' => $age['days'],

                'registration_fee' => $fee,
                'payment_status' => $payer->kind === 'umum' ? 'belum-bayar' : 'dijamin',

                'guardian_name' => $extra['guardian_name'] ?? $patient->guardian_name,
                'guardian_relation' => $extra['guardian_relation'] ?? $patient->guardian_relation,
                'guardian_phone' => $extra['guardian_phone'] ?? $patient->guardian_phone,

                'referral_number' => $extra['referral_number'] ?? null,
                'membership_number' => $extra['membership_number'] ?? null,

                'created_by' => $actorId,
            ]);
        });
    }

    public function cancel(Registration $registration, string $reason, ?int $actorId = null): Registration
    {
        if ($registration->isCancelled()) {
            throw new RegistrationException('Registrasi ini sudah dibatalkan.');
        }

        if ($registration->status === Registration::STATUS_SELESAI) {
            throw new RegistrationException('Registrasi yang sudah selesai tidak bisa dibatalkan.');
        }

        $registration->update([
            'status' => Registration::STATUS_BATAL,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        return $registration->refresh();
    }

    /**
     * Nomor registrasi yang dibaca manusia. Formatnya YYYYMMDD-NNNNN.
     *
     * Ini kolom unik biasa, bukan primary key — beda dari no_rawat Khanza yang
     * dijadikan primary key sekaligus foreign key di puluhan tabel.
     */
    private function allocateRegistrationNumber(CarbonImmutable $date): string
    {
        $prefix = $date->format('Ymd');

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

    /** Pasien lama bila pernah berkunjung sebelum tanggal ini. */
    private function hasPreviousVisit(int $patientId, CarbonImmutable $date): bool
    {
        return Registration::query()
            ->where('patient_id', $patientId)
            ->where('service_date', '<', $date->toDateString())
            ->where('status', '<>', Registration::STATUS_BATAL)
            ->exists();
    }

    /**
     * Menahan pendaftaran ganda di poli yang sama pada hari yang sama.
     *
     * Kejadian nyata di loket ramai: petugas menekan simpan dua kali, atau
     * pasien mengantre di dua loket sekaligus.
     */
    private function guardDoubleRegistration(int $patientId, int $unitId, CarbonImmutable $date): void
    {
        $exists = Registration::query()
            ->where('patient_id', $patientId)
            ->where('unit_id', $unitId)
            ->where('service_date', $date->toDateString())
            ->where('status', '<>', Registration::STATUS_BATAL)
            ->exists();

        if ($exists) {
            throw new RegistrationException(
                'Pasien sudah terdaftar di unit ini pada tanggal yang sama.'
            );
        }
    }
}
