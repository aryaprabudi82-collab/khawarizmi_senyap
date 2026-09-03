<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeSchedule extends Model
{
    public const DAYS = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    protected $table = 'organization.practice_schedules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'is_active' => 'boolean'];
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function dayLabel(): string
    {
        return self::DAYS[$this->day_of_week] ?? (string) $this->day_of_week;
    }
}
