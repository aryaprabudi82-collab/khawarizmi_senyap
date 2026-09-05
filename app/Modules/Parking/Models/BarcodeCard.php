<?php

namespace App\Modules\Parking\Models;

use Illuminate\Database\Eloquent\Model;

class BarcodeCard extends Model
{
    protected $table = 'parking.barcode_cards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
