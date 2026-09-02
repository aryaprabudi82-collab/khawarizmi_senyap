<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class DailyVisitSummary extends Model
{
    public $timestamps = false;

    protected $table = 'reporting.daily_visit_summary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'synced_at' => 'datetime',
        ];
    }
}
