<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class Payer extends Model
{
    protected $table = 'catalog.payers';

    protected $fillable = ['code', 'name', 'kind', 'company_name', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
