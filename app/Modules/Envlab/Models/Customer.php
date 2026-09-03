<?php

namespace App\Modules\Envlab\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    public const KIND_INTERNAL = 'internal';
    public const KIND_EKSTERNAL = 'eksternal';

    protected $table = 'envlab.customers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
