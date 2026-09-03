<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    public $timestamps = false;

    protected $table = 'asset.categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
