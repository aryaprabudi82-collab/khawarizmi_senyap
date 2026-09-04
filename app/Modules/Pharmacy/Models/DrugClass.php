<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

class DrugClass extends Model
{
    protected $table = 'pharmacy.drug_classes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
