<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DutySchedule extends Model
{
    public const STATUS_TERJADWAL = 'terjadwal';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'hr.duty_schedules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['schedule_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }
}
