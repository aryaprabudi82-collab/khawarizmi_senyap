<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/** inventaris_jenis. */
class AssetType extends Model
{
    public $timestamps = false;

    protected $table = 'asset.types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
