<?php

namespace App\Modules\Envlab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SampleType extends Model
{
    public const CATEGORIES = [
        'air-bersih', 'air-limbah', 'udara-ambien', 'udara-ruangan',
        'makanan-minuman', 'usap-alat', 'usap-dinding', 'lainnya',
    ];

    protected $table = 'envlab.sample_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function qualityStandards(): HasMany
    {
        return $this->hasMany(QualityStandard::class, 'sample_type_id');
    }
}
