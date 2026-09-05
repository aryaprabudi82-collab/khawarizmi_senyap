<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\MaintenanceSchedule;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * pemeliharaan_inventaris (+ pemeliharaan_gedung, umbrella-gate lewat
 * location_id — lihat catatan migrasi 2026_10_11_000001). Jadwal
 * interval berulang otomatis: next_due_date dihitung ulang dari
 * last_performed_at + interval_months tiap kali pelaksanaan dicatat.
 */
class AssetMaintenanceScheduleService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function create(array $data, int $createdBy): MaintenanceSchedule
    {
        if (empty($data['asset_id']) && empty($data['location_id'])) {
            throw new AssetException('Jadwal harus menyasar aset atau lokasi/gedung.');
        }

        return MaintenanceSchedule::query()->create([
            'schedule_number' => $this->numbers->allocate('JDW'),
            'asset_id' => $data['asset_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'title' => $data['title'],
            'interval_months' => $data['interval_months'],
            'next_due_date' => now()->addMonths((int) $data['interval_months'])->toDateString(),
            'is_active' => true,
            'created_by' => $createdBy,
        ]);
    }

    /** Mencatat satu pelaksanaan — jadwal berikutnya otomatis dihitung ulang dari tanggal ini. */
    public function recordCompletion(MaintenanceSchedule $jadwal, User $actor, ?string $notes, ?string $performedAt = null): MaintenanceSchedule
    {
        if (! $jadwal->is_active) {
            throw new AssetException('Jadwal ini sudah tidak aktif.');
        }

        return DB::transaction(function () use ($jadwal, $actor, $notes, $performedAt): MaintenanceSchedule {
            $tanggal = $performedAt ?? now()->toDateString();

            $jadwal->logs()->create([
                'performed_at' => $tanggal,
                'performed_by' => $actor->id,
                'notes' => $notes,
            ]);

            $jadwal->update([
                'last_performed_at' => $tanggal,
                'next_due_date' => Carbon::parse($tanggal)->addMonths($jadwal->interval_months)->toDateString(),
            ]);

            return $jadwal->refresh();
        });
    }

    public function deactivate(MaintenanceSchedule $jadwal): MaintenanceSchedule
    {
        $jadwal->update(['is_active' => false]);

        return $jadwal->refresh();
    }
}
