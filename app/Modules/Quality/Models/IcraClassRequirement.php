<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Persyaratan yang harus dipenuhi per kelas pencegahan. Lahir kosong. */
class IcraClassRequirement extends Model
{
    protected $table = 'quality.icra_class_requirements';

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
