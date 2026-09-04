<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class Donor extends Model
{
    protected $table = 'asset.donors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
