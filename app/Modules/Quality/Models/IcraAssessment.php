<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

class IcraAssessment extends Model
{
    public const STATUS_AKTIF = 'aktif';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'quality.icra_assessments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'valid_until' => 'date',
        ];
    }
}
