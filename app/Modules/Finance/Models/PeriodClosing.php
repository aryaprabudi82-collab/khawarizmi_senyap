<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodClosing extends Model
{
    protected $table = 'finance.period_closings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PeriodClosingLine::class);
    }

    public function isReopened(): bool
    {
        return $this->reopened_at !== null;
    }

    public function label(): string
    {
        return str_pad((string) $this->period_month, 2, '0', STR_PAD_LEFT) . '-' . $this->period_year;
    }
}
