<?php

namespace App\Modules\Encounter\Services;

use App\Modules\Encounter\Models\CorporateMcuBooking;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/** booking_mcu_perusahaan — lihat catatan migrasi encounter untuk cakupan Wave 1. */
class CorporateMcuBookingService
{
    /** @throws RegistrationException */
    public function book(array $data, ?User $actor): CorporateMcuBooking
    {
        if ((int) ($data['employee_count'] ?? 0) <= 0) {
            throw new RegistrationException('Jumlah karyawan harus lebih dari nol.');
        }

        return CorporateMcuBooking::query()->create($data + [
            'booking_number' => $this->allocateNumber(),
            'status' => CorporateMcuBooking::STATUS_DIJADWALKAN,
            'booked_by' => $actor?->id,
            'booked_by_name' => $actor?->name,
            'booked_at' => now(),
        ]);
    }

    /** @throws RegistrationException */
    public function complete(CorporateMcuBooking $booking): CorporateMcuBooking
    {
        $this->assertDijadwalkan($booking);
        $booking->update(['status' => CorporateMcuBooking::STATUS_SELESAI]);

        return $booking->refresh();
    }

    /** @throws RegistrationException */
    public function cancel(CorporateMcuBooking $booking): CorporateMcuBooking
    {
        $this->assertDijadwalkan($booking);
        $booking->update(['status' => CorporateMcuBooking::STATUS_DIBATALKAN]);

        return $booking->refresh();
    }

    private function assertDijadwalkan(CorporateMcuBooking $booking): void
    {
        if ($booking->status !== CorporateMcuBooking::STATUS_DIJADWALKAN) {
            throw new RegistrationException('Booking MCU ini sudah ' . CorporateMcuBooking::statusLabel($booking->status) . '.');
        }
    }

    private function allocateNumber(): string
    {
        $prefix = 'MCU-' . now()->format('Ymd');

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
