<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class EnvironmentalMeasurement extends Model
{
    public const CATEGORY_LIMBAH_B3_CAIR = 'limbah-b3-cair';
    public const CATEGORY_LIMBAH_B3_PADAT = 'limbah-b3-padat';
    public const CATEGORY_LIMBAH_DOMESTIK = 'limbah-domestik';
    public const CATEGORY_MUTU_AIR_LIMBAH = 'mutu-air-limbah';
    public const CATEGORY_AIR_PDAM = 'air-pdam';
    public const CATEGORY_AIR_TANAH = 'air-tanah';

    protected $table = 'asset.environmental_measurements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'quantity' => 'decimal:3',
        ];
    }
}
