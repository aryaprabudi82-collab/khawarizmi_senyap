<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'retail.categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
