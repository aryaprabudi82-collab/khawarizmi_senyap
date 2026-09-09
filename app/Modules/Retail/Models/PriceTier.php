<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;

class PriceTier extends Model
{
    protected $table = 'retail.price_tiers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
