<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu masalah keperawatan yang ditegakkan pada seorang pasien.
 *
 * problem_name DISALIN saat dipilih: master keperawatan direvisi mengikuti
 * SDKI, dan asuhan yang sudah ditulis perawat tidak boleh ikut berubah
 * kalimatnya.
 */
class NursingDiagnosis extends Model
{
    protected $table = 'clinical.nursing_diagnoses';

    protected $guarded = ['id'];

    public function formResponse(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function carePlanItems(): HasMany
    {
        return $this->hasMany(NursingCarePlanItem::class, 'nursing_diagnosis_id');
    }
}
