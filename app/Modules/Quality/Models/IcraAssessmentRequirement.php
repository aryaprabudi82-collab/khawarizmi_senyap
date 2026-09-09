<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persyaratan kelas yang DIBEKUKAN ke satu pengkajian.
 *
 * Disalin, bukan dirujuk: revisi SPO tahun depan tidak boleh mengubah
 * daftar persyaratan proyek tahun ini — termasuk proyek yang sudah
 * dinyatakan memenuhi seluruhnya.
 */
class IcraAssessmentRequirement extends Model
{
    protected $table = 'quality.icra_assessment_requirements';

    protected $guarded = ['id'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(IcraAssessment::class, 'assessment_id');
    }

    public function belumDijawab(): bool
    {
        return $this->fulfilled === null;
    }

    protected function casts(): array
    {
        return [
            'fulfilled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}
