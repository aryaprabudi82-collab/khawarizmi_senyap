<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kelas pencegahan ICRA (I-IV) — dari pedoman. */
class IcraPrecautionClass extends Model
{
    protected $table = 'quality.icra_precaution_classes';

    protected $guarded = ['id'];

    public function requirements(): HasMany
    {
        return $this->hasMany(IcraClassRequirement::class, 'precaution_class_id')->orderBy('position');
    }

    public function controlMeasures(): HasMany
    {
        return $this->hasMany(IcraControlMeasure::class, 'precaution_class_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
