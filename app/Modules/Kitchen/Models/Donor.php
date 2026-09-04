<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;

class Donor extends Model
{
    protected $table = 'kitchen.donors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
