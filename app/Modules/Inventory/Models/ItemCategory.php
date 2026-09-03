<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model
{
    public $timestamps = false;

    protected $table = 'inventory.item_categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
