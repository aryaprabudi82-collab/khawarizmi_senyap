<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class AssetLocation extends Model
{
    public $timestamps = false;

    protected $table = 'asset.locations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
