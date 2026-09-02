<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class Tariff extends Model
{
    protected $table = 'catalog.tariffs';

    protected $fillable = [
        'service_id', 'payer_id', 'care_class',
        'amount', 'amount_returning', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_returning' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
}
