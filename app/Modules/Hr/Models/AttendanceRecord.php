<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    public const STATUS_HADIR = 'hadir';
    public const STATUS_IZIN = 'izin';
    public const STATUS_SAKIT = 'sakit';
    public const STATUS_ALPHA = 'alpha';
    public const STATUS_CUTI = 'cuti';

    protected $table = 'hr.attendance_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dutySchedule(): BelongsTo
    {
        return $this->belongsTo(DutySchedule::class);
    }
}
