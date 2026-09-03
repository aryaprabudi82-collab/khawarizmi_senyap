<?php

namespace App\Modules\Envlab\Models;

use Illuminate\Database\Eloquent\Model;

class TestParameter extends Model
{
    protected $table = 'envlab.test_parameters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
