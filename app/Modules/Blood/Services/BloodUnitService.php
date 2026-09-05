<?php

namespace App\Modules\Blood\Services;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Models\Donor;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Siklus status satu unit darah: karantina -> tersedia -> dikeluarkan
 * (lewat TransfusionService), dengan cabang ke ditahan (skrining
 * meragukan, tapi masih bisa dirilis kembali), ditolak (gagal
 * skrining/rusak, final), atau dipisahkan (whole-blood dipecah jadi
 * beberapa unit komponen, final — lihat separate()). Setiap perpindahan
 * status dicatat di unit_status_logs sebagai jejak audit — data paling
 * sensitif dalam modul ini, harus bisa dipertanggungjawabkan siapa
 * mengubah apa kapan.
 */
class BloodUnitService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function collect(array $data): BloodUnit
    {
        if (! empty($data['donor_id'])) {
            $donor = Donor::query()->find($data['donor_id']);

            if ($donor !== null && $donor->isBlocked()) {
                $sampai = $donor->blocked_until !== null ? ' sampai ' . $donor->blocked_until->format('d-m-Y') : ' permanen';
                throw new BloodException("Pendonor {$donor->name} sedang dicekal{$sampai} ({$donor->block_reason}), pengambilan darah tidak bisa dilanjutkan.");
            }
        }

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

    /**
     * utd_pemisahan_darah — pisahkan satu unit whole-blood tersedia jadi
     * beberapa unit komponen (PRC/plasma/platelet). Whole-blood-nya sudah
     * lolos skrining (status tersedia), jadi komponen hasil pisahan
     * langsung tersedia juga, bukan mulai dari karantina lagi.
     *
     * $components: list of ['component' => 'prc'|'plasma'|'platelet', 'volume_ml' => int, 'expiry_date' => string]
     *
     * @return Collection<int, BloodUnit>
     */
    public function separate(BloodUnit $wholeBlood, array $components, User $actor): Collection
    {
        if ($wholeBlood->component !== 'whole-blood') {
            throw new BloodException("Unit darah {$wholeBlood->unit_number} bukan whole-blood, tidak bisa dipisah jadi komponen.");
        }

        if ($wholeBlood->status !== BloodUnit::STATUS_TERSEDIA) {
            throw new BloodException("Unit darah {$wholeBlood->unit_number} berstatus '{$wholeBlood->status}', hanya unit tersedia yang bisa dipisah.");
        }

        if (count($components) < 1) {
            throw new BloodException('Minimal satu komponen hasil pisahan harus diisi.');
        }

        return DB::transaction(function () use ($wholeBlood, $components, $actor): Collection {
            $anak = collect($components)->map(function (array $komponen) use ($wholeBlood): BloodUnit {
                return BloodUnit::query()->create([
                    'unit_number' => $this->numbers->allocate('UTD'),
                    'donor_id' => $wholeBlood->donor_id,
                    'parent_unit_id' => $wholeBlood->id,
                    'blood_type' => $wholeBlood->blood_type,
                    'rhesus' => $wholeBlood->rhesus,
                    'component' => $komponen['component'],
                    'volume_ml' => $komponen['volume_ml'],
                    'collected_at' => $wholeBlood->collected_at,
                    'expiry_date' => $komponen['expiry_date'],
                    'status' => BloodUnit::STATUS_TERSEDIA,
                ]);
            });

            DB::table('blood.unit_status_logs')->insert([
                'blood_unit_id' => $wholeBlood->id,
                'from_status' => BloodUnit::STATUS_TERSEDIA,
                'to_status' => BloodUnit::STATUS_DIPISAHKAN,
                'reason' => 'Dipisahkan menjadi ' . $anak->count() . ' unit komponen: ' . $anak->pluck('unit_number')->implode(', '),
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            $wholeBlood->update(['status' => BloodUnit::STATUS_DIPISAHKAN]);

            return $anak;
        });
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
