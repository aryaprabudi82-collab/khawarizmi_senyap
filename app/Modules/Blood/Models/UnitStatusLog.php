<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Model;

class UnitStatusLog extends Model
{
    public $timestamps = false;

    protected $table = 'blood.unit_status_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
