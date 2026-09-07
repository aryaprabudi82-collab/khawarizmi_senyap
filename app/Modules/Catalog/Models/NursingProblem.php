<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu masalah keperawatan (diagnosis keperawatan) di master.
 *
 * specialty null berarti berlaku umum — bukan berarti belum diisi.
 */
class NursingProblem extends Model
{
    protected $table = 'catalog.nursing_problems';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function carePlans(): HasMany
    {
        return $this->hasMany(NursingCarePlan::class, 'nursing_problem_id');
    }
}
