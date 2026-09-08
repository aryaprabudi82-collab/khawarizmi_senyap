<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'library.categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
