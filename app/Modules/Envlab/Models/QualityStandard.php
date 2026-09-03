<?php

namespace App\Modules\Envlab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityStandard extends Model
{
    protected $table = 'envlab.quality_standards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:4',
            'max_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(SampleType::class, 'sample_type_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(TestParameter::class, 'parameter_id');
    }

    /** Rentang baku mutu yang bisa dibaca manusia, untuk ditampilkan di layar. */
    public function displayRange(): string
    {
        if ($this->qualitative_standard !== null) {
            return $this->qualitative_standard;
        }

        if ($this->min_value !== null && $this->max_value !== null) {
            return $this->min_value . ' – ' . $this->max_value;
        }

        if ($this->max_value !== null) {
            return 'maks. ' . $this->max_value;
        }

        if ($this->min_value !== null) {
            return 'min. ' . $this->min_value;
        }

        return '—';
    }
}
