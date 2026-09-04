<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

class DrugCategory extends Model
{
    protected $table = 'pharmacy.drug_categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
