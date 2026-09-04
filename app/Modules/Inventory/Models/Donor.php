<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/** asal_hibah (non-medis). */
class Donor extends Model
{
    protected $table = 'inventory.donors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
