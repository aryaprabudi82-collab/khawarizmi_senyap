<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class AplicaresRoomMapping extends Model
{
    protected $table = 'integration.aplicares_room_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_reported' => 'boolean'];
    }
}
