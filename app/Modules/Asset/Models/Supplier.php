<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/** suplier_inventaris. */
class Supplier extends Model
{
    protected $table = 'asset.suppliers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
