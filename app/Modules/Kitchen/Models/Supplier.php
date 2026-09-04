<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;

/** dapur_suplier. */
class Supplier extends Model
{
    protected $table = 'kitchen.suppliers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
