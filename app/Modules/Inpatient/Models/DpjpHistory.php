<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;

class DpjpHistory extends Model
{
    protected $table = 'inpatient.dpjp_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime'];
    }
}
