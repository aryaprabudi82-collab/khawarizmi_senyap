<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Encounter\Models\OperationBooking;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** booking_operasi — lihat catatan migrasi encounter untuk alasan bentuknya. */
class OperationBookingService
{
    /** @throws RegistrationException */
    public function book(int $registrationId, array $data, ?User $actor): OperationBooking
    {
        $kunjungan = Registration::query()->find($registrationId)
            ?? throw new RegistrationException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        return OperationBooking::query()->create($data + [
            'booking_number' => $this->allocateNumber(),
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'status' => OperationBooking::STATUS_DIJADWALKAN,
            'booked_by' => $actor?->id,
            'booked_by_name' => $actor?->name,
            'booked_at' => now(),
        ]);
    }

    /** @throws RegistrationException */
    public function complete(OperationBooking $booking): OperationBooking
    {
        $this->assertDijadwalkan($booking);
        $booking->update(['status' => OperationBooking::STATUS_SELESAI]);

        return $booking->refresh();
    }

    /** @throws RegistrationException */
    public function cancel(OperationBooking $booking): OperationBooking
    {
        $this->assertDijadwalkan($booking);
        $booking->update(['status' => OperationBooking::STATUS_DIBATALKAN]);

        return $booking->refresh();
    }

    private function assertDijadwalkan(OperationBooking $booking): void
    {
        if ($booking->status !== OperationBooking::STATUS_DIJADWALKAN) {
            throw new RegistrationException('Jadwal operasi ini sudah ' . OperationBooking::statusLabel($booking->status) . '.');
        }
    }

    private function allocateNumber(): string
    {
        $prefix = 'OPR-' . now()->format('Ymd');

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
