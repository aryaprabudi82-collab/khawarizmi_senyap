<?php

namespace App\Modules\Inpatient\Services;

use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;

/**
 * Master kamar/bed dan siklus status bed: tersedia -> terisi (lewat
 * AdmissionService::admit) -> dibersihkan (lewat ::discharge) -> tersedia
 * lagi lewat markClean() di sini — bed yang baru ditinggal pasien tidak
 * langsung siap dipakai, butuh housekeeping dulu, sama seperti bangsal
 * sungguhan. tidak-aktif dipakai untuk bed rusak/renovasi, di luar siklus itu.
 */
class RoomService
{
    public function createRoom(array $data): Room
    {
        return Room::query()->create($data + ['is_active' => true]);
    }

    public function updateRoom(Room $room, array $data): Room
    {
        $room->update($data);

        return $room->refresh();
    }

    public function addBed(Room $room, string $bedNumber): Bed
    {
        return $room->beds()->create([
            'bed_number' => $bedNumber,
            'status' => Bed::STATUS_TERSEDIA,
        ]);
    }

    public function markClean(Bed $bed): Bed
    {
        $this->assertStatus($bed, Bed::STATUS_DIBERSIHKAN, Bed::STATUS_TERSEDIA);

        $bed->update(['status' => Bed::STATUS_TERSEDIA]);

        return $bed->refresh();
    }

    public function deactivate(Bed $bed): Bed
    {
        if (! in_array($bed->status, [Bed::STATUS_TERSEDIA, Bed::STATUS_DIBERSIHKAN], true)) {
            throw new InpatientException(
                "Bed {$bed->bed_number} sedang terisi pasien, tidak bisa dinonaktifkan — pulangkan pasiennya dulu."
            );
        }

        $bed->update(['status' => Bed::STATUS_TIDAK_AKTIF]);

        return $bed->refresh();
    }

    public function reactivate(Bed $bed): Bed
    {
        $this->assertStatus($bed, Bed::STATUS_TIDAK_AKTIF, Bed::STATUS_TERSEDIA);

        $bed->update(['status' => Bed::STATUS_TERSEDIA]);

        return $bed->refresh();
    }

    private function assertStatus(Bed $bed, string $expected, string $moveTo): void
    {
        if ($bed->status !== $expected) {
            throw new InpatientException(
                "Bed {$bed->bed_number} berstatus '{$bed->status}', tidak bisa dipindah ke '{$moveTo}' dari sana — harus '{$expected}' lebih dulu."
            );
        }
    }
}
