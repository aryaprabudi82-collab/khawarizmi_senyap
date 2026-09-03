<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class CssdItem extends Model
{
    public $timestamps = false;

    protected $table = 'asset.cssd_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
