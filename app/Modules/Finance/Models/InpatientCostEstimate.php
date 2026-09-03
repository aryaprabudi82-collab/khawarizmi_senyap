<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class InpatientCostEstimate extends Model
{
    public const CLASSES = ['vip', 'kelas-1', 'kelas-2', 'kelas-3', 'icu', 'isolasi'];

    protected $table = 'finance.inpatient_cost_estimates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total_estimate' => 'decimal:2',
            'prepared_at' => 'datetime',
        ];
    }

    public static function classLabel(string $class): string
    {
        return match ($class) {
            'vip' => 'VIP',
            'kelas-1' => 'Kelas 1',
            'kelas-2' => 'Kelas 2',
            'kelas-3' => 'Kelas 3',
            'icu' => 'ICU',
            'isolasi' => 'Isolasi',
            default => $class,
        };
    }
}
