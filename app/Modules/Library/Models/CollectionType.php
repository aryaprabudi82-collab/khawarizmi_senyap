<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionType extends Model
{
    protected $table = 'library.collection_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
