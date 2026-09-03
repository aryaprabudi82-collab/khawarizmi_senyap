<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceAppraisal extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';

    protected $table = 'hr.performance_appraisals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Predikat SKP standar ASN — dihitung dari skor, tidak disimpan terpisah. */
    public function predicate(): string
    {
        return match (true) {
            $this->score >= 91 => 'Sangat Baik',
            $this->score >= 76 => 'Baik',
            $this->score >= 61 => 'Cukup',
            $this->score >= 51 => 'Kurang',
            default => 'Sangat Kurang',
        };
    }
}
