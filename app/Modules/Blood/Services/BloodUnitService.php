<?php

namespace App\Modules\Blood\Services;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Siklus status satu unit darah: karantina -> tersedia -> dikeluarkan
 * (lewat TransfusionService), dengan cabang ke ditahan (skrining
 * meragukan, tapi masih bisa dirilis kembali) atau ditolak (gagal
 * skrining/rusak, final). Setiap perpindahan status dicatat di
 * unit_status_logs sebagai jejak audit — data paling sensitif dalam
 * modul ini, harus bisa dipertanggungjawabkan siapa mengubah apa kapan.
 */
class BloodUnitService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function collect(array $data): BloodUnit
    {
        return BloodUnit::query()->create($data + [
            'unit_number' => $this->numbers->allocate('UTD'),
            'status' => BloodUnit::STATUS_KARANTINA,
        ]);
    }

    /** Merilis unit ke status tersedia — dari karantina (lolos skrining) atau ditahan (hasil ulang bersih). */
    public function release(BloodUnit $unit, User $actor, ?string $reason = null): BloodUnit
    {
        return $this->transition($unit, [BloodUnit::STATUS_KARANTINA, BloodUnit::STATUS_DITAHAN], BloodUnit::STATUS_TERSEDIA, $actor, $reason);
    }

    /** Menahan unit yang sudah tersedia — mis. hasil skrining ulang meragukan. */
    public function hold(BloodUnit $unit, User $actor, string $reason): BloodUnit
    {
        return $this->transition($unit, [BloodUnit::STATUS_TERSEDIA], BloodUnit::STATUS_DITAHAN, $actor, $reason);
    }

    /** Menolak unit — gagal skrining atau rusak. Final, tidak bisa dirilis lagi. */
    public function reject(BloodUnit $unit, User $actor, string $reason): BloodUnit
    {
        return $this->transition($unit, [BloodUnit::STATUS_KARANTINA, BloodUnit::STATUS_DITAHAN], BloodUnit::STATUS_DITOLAK, $actor, $reason);
    }

    private function transition(BloodUnit $unit, array $allowedFrom, string $to, User $actor, ?string $reason): BloodUnit
    {
        if (! in_array($unit->status, $allowedFrom, true)) {
            throw new BloodException("Unit darah {$unit->unit_number} berstatus '{$unit->status}', tidak bisa dipindah ke '{$to}' dari sana.");
        }

        return DB::transaction(function () use ($unit, $to, $actor, $reason): BloodUnit {
            DB::table('blood.unit_status_logs')->insert([
                'blood_unit_id' => $unit->id,
                'from_status' => $unit->status,
                'to_status' => $to,
                'reason' => $reason,
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            $unit->update(['status' => $to]);

            return $unit->refresh();
        });
    }
}
