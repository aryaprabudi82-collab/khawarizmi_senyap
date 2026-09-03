<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Model;

class Donor extends Model
{
    protected $table = 'blood.donors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
