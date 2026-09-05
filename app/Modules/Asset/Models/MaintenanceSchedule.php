<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** pemeliharaan_inventaris (+ pemeliharaan_gedung lewat location_id, umbrella-gate). */
class MaintenanceSchedule extends Model
{
    protected $table = 'asset.maintenance_schedules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_performed_at' => 'date',
            'next_due_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceScheduleLog::class, 'schedule_id');
    }

    public function isOverdue(): bool
    {
        return $this->is_active && $this->next_due_date->isPast();
    }

    /** Target jadwal: nama aset kalau pemeliharaan_inventaris, nama lokasi kalau pemeliharaan_gedung. */
    public function targetLabel(): string
    {
        return $this->asset?->name ?? $this->location?->name ?? '—';
    }
}
