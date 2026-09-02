<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class DailyRevenueSummary extends Model
{
    public $timestamps = false;

    protected $table = 'reporting.daily_revenue_summary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'total_amount' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }
}
