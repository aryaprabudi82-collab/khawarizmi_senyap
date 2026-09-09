<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tindakan pengendalian. LAHIR KOSONG: kalimatnya adalah SPO RSP UI, dan
 * mengarangnya berarti menerbitkan perintah kerja konstruksi yang tidak
 * pernah disahkan siapa pun.
 */
class IcraControlMeasure extends Model
{
    protected $table = 'quality.icra_control_measures';

    protected $guarded = ['id'];

    public function precautionClass(): BelongsTo
    {
        return $this->belongsTo(IcraPrecautionClass::class, 'precaution_class_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
