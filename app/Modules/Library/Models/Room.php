<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $table = 'library.rooms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
