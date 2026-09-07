<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu rencana keperawatan di master.
 *
 * MILIK masalahnya — diambil dari foreign key yang sudah ada di skema
 * Khanza sendiri. Rencana tanpa masalah adalah intervensi tanpa indikasi.
 */
class NursingCarePlan extends Model
{
    protected $table = 'catalog.nursing_care_plans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(NursingProblem::class, 'nursing_problem_id');
    }
}
