<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceScheduleLog extends Model
{
    protected $table = 'asset.maintenance_schedule_logs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['performed_at' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'schedule_id');
    }
}
