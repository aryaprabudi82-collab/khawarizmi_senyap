<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu rencana keperawatan yang dipilih untuk sebuah diagnosis.
 *
 * Melekat pada DIAGNOSIS-nya, bukan pada lembar asesmen — supaya tidak ada
 * intervensi yang berdiri tanpa indikasi.
 */
class NursingCarePlanItem extends Model
{
    protected $table = 'clinical.nursing_care_plan_items';

    protected $guarded = ['id'];

    public const DIRENCANAKAN = 'direncanakan';
    public const DIKERJAKAN = 'dikerjakan';
    public const DIHENTIKAN = 'dihentikan';

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(NursingDiagnosis::class, 'nursing_diagnosis_id');
    }
}
