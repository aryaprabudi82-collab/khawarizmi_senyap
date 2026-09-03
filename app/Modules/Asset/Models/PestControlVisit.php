<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

class PestControlVisit extends Model
{
    protected $table = 'asset.pest_control_visits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['visited_on' => 'date'];
    }
}
