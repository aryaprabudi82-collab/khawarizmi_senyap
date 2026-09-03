<?php

namespace App\Modules\Envlab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleTestItem extends Model
{
    protected $table = 'envlab.sample_test_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'standard_min' => 'decimal:4',
            'standard_max' => 'decimal:4',
            'result_value' => 'decimal:4',
            'is_exceeded' => 'boolean',
            'entered_at' => 'datetime',
        ];
    }

    public function sampleTest(): BelongsTo
    {
        return $this->belongsTo(SampleTest::class, 'sample_test_id');
    }

    public function hasResult(): bool
    {
        return $this->result_value !== null || $this->result_text !== null;
    }

    /** Baku mutu yang bisa dibaca manusia, disalin saat permintaan dibuat. */
    public function standardDisplay(): string
    {
        if ($this->standard_qualitative !== null) {
            return $this->standard_qualitative;
        }

        if ($this->standard_min !== null && $this->standard_max !== null) {
            return $this->standard_min . ' – ' . $this->standard_max;
        }

        if ($this->standard_max !== null) {
            return 'maks. ' . $this->standard_max;
        }

        if ($this->standard_min !== null) {
            return 'min. ' . $this->standard_min;
        }

        return '—';
    }
}
